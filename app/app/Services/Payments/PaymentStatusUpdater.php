<?php

namespace App\Services\Payments;

use App\Events\OrderCanceled;
use App\Events\OrderPaid;
use App\Events\OrderPaymentRefused;
use App\Events\OrderRefunded;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Aplica um status vindo do gateway (criação, webhook, postback ou consulta) ao pedido.
 * Idempotente: aplicar o mesmo status duas vezes não dispara eventos em dobro.
 */
class PaymentStatusUpdater
{
    public function apply(Order $order, string $gatewayStatus, string $source, array $payload = []): Order
    {
        $new = NixfyGateway::mapStatus($gatewayStatus);

        $fire = DB::transaction(function () use ($order, $new, $source, $payload, $gatewayStatus) {
            $order = Order::whereKey($order->id)->lockForUpdate()->first();
            $old = $order->payment_status;
            if ($old === $new) {
                return null;
            }
            // Não regredir: um "pending" atrasado não pode desfazer um "paid".
            if (in_array($new, ['pending', 'processing']) && in_array($old, ['paid', 'refunded', 'chargeback', 'med', 'canceled', 'refused'])) {
                return null;
            }

            $order->payment_status = $new;
            if (isset($payload['fee'])) {
                $order->gateway_fee = (int) $payload['fee'];
            }

            $event = null;
            switch ($new) {
                case 'paid':
                    if (! $order->paid_at) {
                        $order->paid_at = now();
                        $event = OrderPaid::class;
                    }
                    if (in_array($order->status, ['pending', 'canceled'])) {
                        $order->status = 'paid';
                    }
                    break;
                case 'canceled':
                case 'failed':
                    if ($order->status === 'pending') {
                        $order->status = 'canceled';
                        $order->canceled_at = now();
                        $event = OrderCanceled::class;
                    }
                    break;
                case 'refused':
                    $event = OrderPaymentRefused::class; // cartão recusado: pedido segue pendente p/ nova tentativa
                    break;
                case 'refunded':
                case 'chargeback':
                    $order->status = 'refunded';
                    $event = OrderRefunded::class;
                    break;
            }

            $order->save();
            $order->log('payment', "Pagamento: {$old} → {$new} (via {$source})", ['gateway_status' => $gatewayStatus]);

            return $event ? [$event, $order] : null;
        });

        if ($fire) {
            [$eventClass, $fresh] = $fire;
            event(new $eventClass($fresh));

            return $fresh;
        }

        return $order->refresh();
    }
}
