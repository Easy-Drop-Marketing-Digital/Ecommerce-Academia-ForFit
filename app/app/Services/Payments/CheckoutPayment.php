<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * Ponto único que o checkout chama para cobrar um pedido já criado.
 * Os dados do cartão existem só nesta requisição (memória) e seguem direto para a Nixfy.
 */
class CheckoutPayment
{
    public function __construct(
        private NixfyGateway $gateway,
        private CardRiskGuard $guard,
        private PaymentStatusUpdater $updater,
    ) {}

    public function pix(Order $order): Order
    {
        $sale = $this->gateway->createSale($order);
        $pix = $sale['payment']['pix'] ?? [];

        $order->forceFill([
            'gateway_uuid' => $sale['uuid'],
            'pix_code' => $pix['key'] ?? null,
            'pix_qr_base64' => $pix['qrCodeBase64'] ?? null,
            'pix_expires_at' => isset($pix['expiresAt']) ? \Illuminate\Support\Carbon::parse($pix['expiresAt']) : now()->addDays((int) config('services.nixfy.pix_expires_in_days', 1)),
        ])->save();
        $order->log('payment', 'PIX gerado na Nixfy', ['uuid' => $sale['uuid']]);

        return $this->updater->apply($order, $sale['status'] ?? 'PENDENTE', 'create');
    }

    /**
     * @param  array  $card  ['number','holder_name','exp_month','exp_year','cvv']
     * @throws GatewayException  com mensagem segura para a cliente
     */
    public function card(Order $order, array $card, ?string $ip): Order
    {
        $check = $this->guard->assess($order, $card, $ip);

        if ($check['blocked']) {
            $this->guard->record($order, $check, 'blocked', $card, null, $ip);
            $order->log('fraud', 'Pagamento com cartão bloqueado pelo antifraude', ['score' => $check['score'], 'flags' => $check['flags']]);
            [$id, $score, $flags] = [$order->id, $check['score'], $check['flags']];
            dispatch(fn () => app(\App\Services\Mail\OrderEmails::class)->cardBlocked(Order::find($id), $score, $flags))->afterResponse();
            // Mensagem neutra: não revela à fraudadora qual regra disparou
            throw new GatewayException('Não foi possível aprovar este cartão. Tente outro cartão ou pague com PIX.');
        }

        try {
            $sale = $this->gateway->createSale($order, $card);
        } catch (GatewayException $e) {
            $this->guard->record($order, $check, 'error', $card, null, $ip);
            throw $e;
        }

        $status = NixfyGateway::mapStatus($sale['status'] ?? null);
        $brand = $sale['payment']['card']['brand'] ?? null;
        $order->forceFill(['gateway_uuid' => $sale['uuid']])->save();
        $this->guard->record($order, $check, $status, $card, $brand, $ip);

        $order = $this->updater->apply($order, $sale['status'] ?? 'EM_PROCESSAMENTO', 'create');

        if ($status === 'refused') {
            throw new GatewayException('Pagamento recusado pelo banco emissor. Confira os dados, tente outro cartão ou pague com PIX.');
        }

        return $order;
    }
}
