<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Support\StoreSettings;
use Illuminate\Support\Facades\DB;

/**
 * Antifraude de cartão baseado em impressão digital (sem armazenar número/CVV).
 *
 * Fluxo no checkout:
 *   $check = $guard->assess($order, $card, $ip);   // antes de chamar a Nixfy
 *   if ($check['blocked']) → mostra erro genérico, não envia ao gateway
 *   ... createSale ...
 *   $guard->record($order, $check, $statusDoGateway);
 *
 * Sinais avaliados:
 *  - mesmo cartão usado por vários CPFs/e-mails (30 dias)       → cartão roubado/compartilhado
 *  - mesmo CPF/e-mail testando vários cartões diferentes (24h)  → teste de cartões
 *  - muitas recusas vindas do mesmo IP (1h)                      → robô de "card testing"
 *  - cartão que já gerou chargeback/estorno antes                → bloqueio direto
 */
class CardRiskGuard
{
    public static function fingerprint(string $cardNumber): string
    {
        $pan = preg_replace('/\D/', '', $cardNumber);
        $key = hash_hmac('sha256', 'card-fingerprint-v1', (string) config('app.key'));

        return hash_hmac('sha256', $pan, $key);
    }

    public static function bin(string $cardNumber): string
    {
        return substr(preg_replace('/\D/', '', $cardNumber), 0, 6);
    }

    /** @return array{fingerprint:string,bin:string,last4:string,score:int,flags:array,blocked:bool} */
    public function assess(Order $order, array $card, ?string $ip): array
    {
        $fp = static::fingerprint($card['number']);
        $pan = preg_replace('/\D/', '', $card['number']);
        $flags = [];
        $score = 0;

        $maxCustomersPerCard = (int) StoreSettings::get('antifraud.max_customers_per_card_30d', 2);
        $maxCardsPerCustomer = (int) StoreSettings::get('antifraud.max_cards_per_customer_24h', 3);
        $maxRefusedPerIp = (int) StoreSettings::get('antifraud.max_refused_per_ip_1h', 5);

        // 1) Cartão já envolvido em chargeback/estorno
        $badHistory = DB::table('orders')->where('card_fingerprint', $fp)
            ->whereIn('payment_status', ['chargeback', 'med'])->exists();
        if ($badHistory) {
            $flags[] = 'card_has_chargeback';
            $score += 100;
        }

        // 2) Mesmo cartão em vários CPFs diferentes (30 dias)
        $docs = DB::table('card_attempts')->where('fingerprint', $fp)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('document')->where('document', '!=', $order->customer_document)
            ->distinct()->count('document');
        if ($docs + 1 > $maxCustomersPerCard) {
            $flags[] = "card_used_by_{$docs}_other_customers";
            $score += 60;
        }

        // 3) Mesmo CPF/e-mail tentando vários cartões (24h)
        $cards = DB::table('card_attempts')
            ->where(fn ($q) => $q->where('document', $order->customer_document)->orWhere('email', $order->customer_email))
            ->where('created_at', '>=', now()->subDay())
            ->where('fingerprint', '!=', $fp)
            ->distinct()->count('fingerprint');
        if ($cards + 1 > $maxCardsPerCustomer) {
            $flags[] = "customer_tried_{$cards}_other_cards";
            $score += 50;
        }

        // 4) Robô de teste de cartão: muitas recusas pelo mesmo IP (1h)
        if ($ip) {
            $refused = DB::table('card_attempts')->where('ip', $ip)
                ->whereIn('status', ['refused', 'blocked'])
                ->where('created_at', '>=', now()->subHour())->count();
            if ($refused >= $maxRefusedPerIp) {
                $flags[] = "ip_{$refused}_refusals_1h";
                $score += 70;
            }
        }

        // 5) Nome do titular muito diferente do nome da cliente (sinal fraco, só soma pontos)
        if (! $this->namesLookAlike($card['holder_name'] ?? '', $order->customer_name)) {
            $flags[] = 'holder_name_mismatch';
            $score += 10;
        }

        $score = min(100, $score);
        $block = (bool) (int) StoreSettings::get('antifraud.block_on_limit', 1);

        return [
            'fingerprint' => $fp,
            'bin' => substr($pan, 0, 6),
            'last4' => substr($pan, -4),
            'score' => $score,
            'flags' => $flags,
            'blocked' => $block && $score >= 50,
        ];
    }

    /** Registra a tentativa e grava os dados não sensíveis do cartão no pedido. */
    public function record(Order $order, array $check, string $status, array $card = [], ?string $brand = null, ?string $ip = null): void
    {
        DB::table('card_attempts')->insert([
            'order_id' => $order->id,
            'fingerprint' => $check['fingerprint'],
            'bin' => $check['bin'],
            'last4' => $check['last4'],
            'brand' => $brand,
            'email' => $order->customer_email,
            'document' => $order->customer_document,
            'ip' => $ip,
            'status' => $status,
            'amount' => $order->total,
            'flags' => $check['flags'] ? json_encode($check['flags']) : null,
            'created_at' => now(),
        ]);

        $order->forceFill([
            'card_fingerprint' => $check['fingerprint'],
            'card_bin' => $check['bin'],
            'card_last4' => $check['last4'],
            'card_brand' => $brand ?? $order->card_brand,
            'card_holder' => isset($card['holder_name']) ? mb_strtoupper(trim($card['holder_name'])) : $order->card_holder,
            'card_exp' => isset($card['exp_month'], $card['exp_year'])
                ? str_pad((string) $card['exp_month'], 2, '0', STR_PAD_LEFT).'/'.(strlen((string) $card['exp_year']) === 2 ? '20'.$card['exp_year'] : $card['exp_year'])
                : $order->card_exp,
            'risk_score' => max((int) $order->risk_score, (int) $check['score']),
            'risk_flags' => $check['flags'] ?: $order->risk_flags,
        ])->save();
    }

    private function namesLookAlike(string $holder, string $customer): bool
    {
        $norm = fn ($s) => array_filter(explode(' ', preg_replace('/[^a-z ]/', '', strtolower(\Illuminate\Support\Str::ascii($s)))));
        $h = $norm($holder);
        $c = $norm($customer);
        if (! $h || ! $c) {
            return true;
        }

        return (bool) array_intersect($h, $c);
    }
}
