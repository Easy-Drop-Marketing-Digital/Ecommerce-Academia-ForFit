<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente da API Nixfy Gateway — https://docs.nixfypagamentos.com
 *
 * IMPORTANTE (PCI): a API da Nixfy recebe número/CVV do cartão no corpo do POST /sales.
 * Esses dados trafegam pelo nosso servidor só em memória — nunca são gravados em banco
 * nem em log (ver sanitize()). Exige HTTPS em todo o site.
 */
class NixfyGateway
{
    /** Status Nixfy → payment_status interno */
    public const STATUS_MAP = [
        'PENDENTE' => 'pending',
        'EM_PROCESSAMENTO' => 'processing',
        'PAGO' => 'paid',
        'CANCELADO' => 'canceled',
        'RECUSADO' => 'refused',
        'ESTORNADO' => 'refunded',
        'FALHA' => 'failed',
        'CHARGEBACK' => 'chargeback',
        'MED' => 'med',
    ];

    public function __construct(private array $config = [])
    {
        $this->config = $config ?: config('services.nixfy');
    }

    /**
     * Cria a venda na Nixfy.
     *
     * @param  array|null  $card  ['number','holder_name','exp_month','exp_year','cvv'] — somente para cartão
     * @return array  objeto `sale` retornado pela Nixfy
     */
    public function createSale(Order $order, ?array $card = null): array
    {
        if ($this->sandbox()) {
            return $this->sandboxSale($order, $card);
        }

        $isCard = $order->payment_method === 'credit_card';
        $addr = $order->shipping_address;

        $body = [
            'amount' => $order->total,
            'paymentMethod' => $isCard ? 'CREDIT_CARD' : 'PIX',
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'document' => ['type' => strlen($order->customer_document) === 14 ? 'cnpj' : 'cpf', 'number' => $order->customer_document],
                'phone' => preg_replace('/\D/', '', $order->customer_phone),
            ],
            'items' => $this->items($order),
            'shipping' => [
                'street' => $addr['street'],
                'streetNumber' => (string) $addr['number'],
                'complement' => $addr['complement'] ?? '',
                'zipCode' => preg_replace('/\D/', '', $addr['zip']),
                'neighborhood' => $addr['neighborhood'],
                'city' => $addr['city'],
                'state' => strtoupper($addr['state']),
                'country' => 'br',
            ],
            'postbackUrl' => route('webhooks.nixfy.postback'),
            'utmSource' => $order->utm_source,
            'utmMedium' => $order->utm_medium,
            'utmCampaign' => $order->utm_campaign,
            'utmContent' => $order->utm_content,
            'utmTerm' => $order->utm_term,
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->number,
                'store' => \Illuminate\Support\Str::slug((string) setting('store.name'), ''), // identificador da loja no painel da Nixfy
            ],
        ];

        if ($isCard) {
            if (! $card) {
                throw new RuntimeException('Dados do cartão ausentes.');
            }
            $body['card'] = [
                'number' => preg_replace('/\D/', '', $card['number']),
                'holderName' => mb_strtoupper(trim($card['holder_name'])),
                'expirationMonth' => str_pad((string) $card['exp_month'], 2, '0', STR_PAD_LEFT),
                'expirationYear' => strlen((string) $card['exp_year']) === 2 ? '20'.$card['exp_year'] : (string) $card['exp_year'],
                'cvv' => (string) $card['cvv'],
            ];
            $body['installments'] = ['number' => max(1, min(12, (int) $order->installments))];
        } else {
            // a validade vem do admin como texto; a Nixfy exige número (texto → HTTP 400 "Expected number")
            $body['pix'] = ['expiresInDays' => max(1, (int) ($this->config['pix_expires_in_days'] ?? 1))];
        }

        $body = array_filter($body, fn ($v) => $v !== null);

        try {
            $res = $this->http()->post('/sales', $body);
        } catch (ConnectionException $e) {
            $this->logTransaction($order, $body, ['error' => $e->getMessage()], 0, 'failed');
            throw new GatewayException('Não foi possível conectar ao processador de pagamento. Tente novamente.', previous: $e);
        }

        $json = $res->json() ?? [];
        $sale = $json['sale'] ?? null;
        $this->logTransaction($order, $body, $json, $res->status(), $sale['status'] ?? 'ERROR', $sale['uuid'] ?? null);

        if (! $res->successful() || ! $sale) {
            throw new GatewayException($this->friendlyError($res->status(), $json));
        }

        return $sale;
    }

    public function getTransaction(string $uuid): array
    {
        if ($this->sandbox()) {
            return ['uuid' => $uuid, 'status' => \Illuminate\Support\Facades\Cache::get("sandbox.tx.$uuid", 'PENDENTE'), 'systemFee' => 0];
        }

        $res = $this->http()->get('/transactions/'.urlencode($uuid));
        if (! $res->successful()) {
            throw new GatewayException('Transação não encontrada no gateway.');
        }

        return $res->json();
    }

    public static function mapStatus(?string $status): string
    {
        return self::STATUS_MAP[strtoupper((string) $status)] ?? 'pending';
    }

    /** Webhook permanente: sha256=HMAC(secret, "<timestamp>.<raw body>"), janela de 5 min. */
    public function verifyWebhook(string $rawBody, ?string $timestamp, ?string $signature): bool
    {
        $secret = $this->config['webhook_secret'] ?? null;
        if (! $secret || ! $timestamp || ! $signature || abs(time() - (int) $timestamp) > 300) {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /** Postback por venda: HMAC-SHA256 hex do JSON enviado (header X-Signature). */
    public function verifyPostback(string $rawBody, ?string $signature): bool
    {
        $secret = $this->config['postback_secret'] ?? null;
        if (! $secret || ! $signature) {
            return false;
        }
        $candidates = [
            $rawBody,
            // Fallback: o gateway assina JSON.stringify(payload) — re-serializa sem escapes, como no JS
            json_encode(json_decode($rawBody, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
        foreach ($candidates as $c) {
            if (hash_equals(hash_hmac('sha256', (string) $c, $secret), strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sandbox local (NIXFY_SANDBOX=true, nunca em produção): simula a Nixfy para testar o checkout sem chaves.
     * Cartão terminado em 0002 = recusado; demais = aprovado. PIX fica pendente até /dev/sandbox/pagar/{uuid}.
     */
    public function sandbox(): bool
    {
        return (bool) ($this->config['sandbox'] ?? false) && ! app()->isProduction();
    }

    private function sandboxSale(Order $order, ?array $card): array
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $isCard = $order->payment_method === 'credit_card';
        $refused = $isCard && str_ends_with(preg_replace('/\D/', '', $card['number'] ?? ''), '0002');
        $status = $isCard ? ($refused ? 'RECUSADO' : 'PAGO') : 'PENDENTE';
        \Illuminate\Support\Facades\Cache::put("sandbox.tx.$uuid", $status, now()->addDay());

        $sale = ['id' => 0, 'uuid' => $uuid, 'amount' => (string) $order->total, 'status' => $status,
            'paymentMethod' => $isCard ? 'CREDIT_CARD' : 'PIX', 'payment' => $isCard
                ? ['method' => 'CREDIT_CARD', 'card' => ['lastDigits' => substr(preg_replace('/\D/', '', $card['number']), -4), 'brand' => 'VISA']]
                : ['method' => 'PIX', 'pix' => ['key' => '00020126580014BR.GOV.BCB.PIX0136SANDBOX-'.$uuid.'5204000053039865802BR5912LOJA SANDBOX6009SAO PAULO', 'qrCodeBase64' => null, 'expiresAt' => now()->addDay()->toIso8601String()]]];
        $this->logTransaction($order, ['sandbox' => true, 'amount' => $order->total], ['sale' => $sale], 201, $status, $uuid);

        return $sale;
    }

    private function http()
    {
        return Http::baseUrl($this->config['base_url'])
            ->withHeaders([
                'X-Api-Public-Key' => $this->config['public_key'],
                'X-Api-Private-Key' => $this->config['private_key'],
            ])
            ->acceptJson()->asJson()
            ->timeout(45)->connectTimeout(10);
    }

    private function items(Order $order): array
    {
        $items = $order->items->map(fn ($i) => [
            'id' => (string) ($i->sku ?: $i->variant_id),
            'title' => mb_substr($i->title.($i->variant_title ? ' - '.$i->variant_title : ''), 0, 120),
            'unitPrice' => (int) $i->unit_price,
            'quantity' => (int) $i->quantity,
            'tangible' => true,
        ])->values()->all();

        // A soma dos itens precisa bater com o amount. A API não tem campo de desconto, então:
        // descontos reduzem o preço unitário (rateio), frete e juros entram como linhas próprias.
        $discount = (int) $order->discount_total;
        if ($discount > 0) {
            $items = $this->distributeDiscount($items, $discount);
        }
        if ($order->shipping_total > 0) {
            $items[] = ['id' => 'frete', 'title' => 'Frete - '.($order->shipping_method ?: 'Entrega'), 'unitPrice' => (int) $order->shipping_total, 'quantity' => 1, 'tangible' => true];
        }
        if ($order->interest_total > 0) {
            $items[] = ['id' => 'juros', 'title' => "Juros do parcelamento ({$order->installments}x)", 'unitPrice' => (int) $order->interest_total, 'quantity' => 1, 'tangible' => false];
        }

        // Garantia final: qualquer centavo de diferença (arredondamento) vai para o primeiro item
        $diff = $order->total - array_sum(array_map(fn ($i) => $i['unitPrice'] * $i['quantity'], $items));
        if ($diff !== 0 && isset($items[0])) {
            if ($items[0]['quantity'] > 1) {
                $first = $items[0];
                $items[0]['quantity']--;
                array_unshift($items, array_merge($first, ['quantity' => 1]));
            }
            $items[0]['unitPrice'] = max(1, $items[0]['unitPrice'] + $diff);
        }

        return array_values($items);
    }

    private function distributeDiscount(array $items, int $discount): array
    {
        // Achata para quantidade 1 para conseguir ratear centavo a centavo
        $flat = [];
        foreach ($items as $i) {
            for ($q = 0; $q < $i['quantity']; $q++) {
                $flat[] = array_merge($i, ['quantity' => 1]);
            }
        }
        $total = array_sum(array_column($flat, 'unitPrice'));
        $left = $discount;
        foreach ($flat as $k => $i) {
            $share = $k === array_key_last($flat) ? $left : (int) floor($discount * $i['unitPrice'] / max(1, $total));
            $share = min($share, $i['unitPrice'] - 1);
            $flat[$k]['unitPrice'] -= $share;
            $left -= $share;
        }

        return $flat;
    }

    private function logTransaction(Order $order, array $request, array $response, int $http, string $status, ?string $uuid = null): void
    {
        PaymentTransaction::create([
            'order_id' => $order->id,
            'gateway' => 'nixfy',
            'gateway_uuid' => $uuid,
            'method' => $order->payment_method,
            'status' => $status,
            'amount' => $order->total,
            'request' => $this->sanitize($request),
            'response' => $this->sanitize($response),
            'http_status' => $http,
        ]);
    }

    /** Remove dados sensíveis de cartão e o QR base64 (pesado) antes de gravar. */
    private function sanitize(array $data): array
    {
        if (isset($data['card'])) {
            $n = preg_replace('/\D/', '', $data['card']['number'] ?? '');
            $data['card'] = ['last4' => substr($n, -4), 'bin' => substr($n, 0, 6)];
        }
        if (isset($data['sale']['payment']['pix']['qrCodeBase64'])) {
            $data['sale']['payment']['pix']['qrCodeBase64'] = '[omitido]';
        }

        return $data;
    }

    private function friendlyError(int $status, array $json): string
    {
        if ($status === 403 && ($json['code'] ?? null) === 'BLACKLISTED_PRODUCTS') {
            report(new RuntimeException('Nixfy bloqueou produtos: '.json_encode($json['violations'] ?? [])));

            return 'Não foi possível processar este pedido. Fale com nosso atendimento.';
        }
        if ($status === 401) {
            report(new RuntimeException('Nixfy: credenciais inválidas'));
        }

        return match (true) {
            $status === 400, $status === 422 => 'Confira os dados informados e tente novamente.',
            $status === 429 => 'Muitas tentativas. Aguarde alguns segundos e tente novamente.',
            default => 'Não foi possível processar o pagamento agora. Tente novamente em instantes.',
        };
    }
}
