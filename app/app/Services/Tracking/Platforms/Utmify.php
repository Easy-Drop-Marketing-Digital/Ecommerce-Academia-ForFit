<?php

namespace App\Services\Tracking\Platforms;

use App\Models\Order;
use Illuminate\Support\Facades\Http;

/**
 * UTMify (opcional) — envia o pedido e cada mudança de status para a atribuição de vendas por UTM.
 * Endpoint de pedidos da API de credenciais da UTMify (cabeçalho x-api-token).
 */
class Utmify implements Platform
{
    public function name(): string
    {
        return 'utmify';
    }

    public function enabled(): bool
    {
        return (bool) config('services.utmify.api_token');
    }

    public function batchSize(): int
    {
        return 1;
    }

    public function send(array $events): array
    {
        $res = Http::timeout(15)->connectTimeout(5)->retry(2, 500, throw: false)
            ->withHeaders(['x-api-token' => (string) config('services.utmify.api_token')])
            ->asJson()->post('https://api.utmify.com.br/api-credentials/orders', array_values($events)[0]);

        return ['ok' => $res->successful(), 'status' => $res->status(), 'body' => $res->json() ?? ['raw' => mb_substr($res->body(), 0, 300)]];
    }

    public static function payload(Order $o): array
    {
        $status = match ($o->payment_status) {
            'paid' => 'paid',
            'refused', 'failed' => 'refused',
            'refunded' => 'refunded',
            'chargeback', 'med' => 'chargedback',
            default => 'waiting_payment',
        };
        $attr = $o->attribution ?? [];
        $utc = fn ($d) => $d?->copy()->utc()->format('Y-m-d H:i:s');

        return [
            'orderId' => (string) $o->number,
            'platform' => \Illuminate\Support\Str::studly((string) setting('store.name')), // nome da loja (ex.: ForFit)
            'paymentMethod' => $o->payment_method === 'pix' ? 'pix' : 'credit_card',
            'status' => $status,
            'createdAt' => $utc($o->created_at),
            'approvedDate' => $utc($o->paid_at),
            'refundedAt' => $status === 'refunded' ? $utc($o->updated_at) : null,
            'customer' => [
                'name' => $o->customer_name,
                'email' => $o->customer_email,
                'phone' => $o->customer_phone,
                'document' => $o->customer_document,
                'country' => 'BR',
                'ip' => $o->client_ip,
            ],
            'products' => $o->items->map(fn ($i) => [
                'id' => (string) $i->product_id,
                'name' => $i->title,
                'planId' => null,
                'planName' => null,
                'quantity' => $i->quantity,
                'priceInCents' => $i->unit_price,
            ])->values()->all(),
            'trackingParameters' => [
                'src' => $attr['src'] ?? null,
                'sck' => $attr['sck'] ?? null,
                'utm_source' => $o->utm_source,
                'utm_campaign' => $o->utm_campaign,
                'utm_medium' => $o->utm_medium,
                'utm_content' => $o->utm_content,
                'utm_term' => $o->utm_term,
            ],
            'commission' => [
                'totalPriceInCents' => $o->total,
                'gatewayFeeInCents' => (int) $o->gateway_fee,
                'userCommissionInCents' => max(0, $o->total - (int) $o->gateway_fee),
            ],
            'isTest' => ! app()->isProduction(),
        ];
    }
}
