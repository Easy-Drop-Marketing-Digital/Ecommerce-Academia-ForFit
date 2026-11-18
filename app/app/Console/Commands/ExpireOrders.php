<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Payments\NixfyGateway;
use App\Services\Payments\PaymentStatusUpdater;
use Illuminate\Console\Command;

/**
 * Cancela pedidos que não foram pagos e devolve o estoque reservado.
 *  - PIX vencido (com 30 min de tolerância) — antes de cancelar, reconsulta a Nixfy (pode ter sido pago e o webhook atrasou)
 *  - Cartão recusado/falha sem nova tentativa em 6 horas
 * Agendado a cada 5 minutos (routes/console.php).
 */
class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Cancela pedidos não pagos (PIX vencido, cartão recusado) e devolve o estoque';

    public function handle(NixfyGateway $gateway, PaymentStatusUpdater $updater): int
    {
        $pix = Order::where('status', 'pending')->where('payment_method', 'pix')
            ->whereNotNull('pix_expires_at')->where('pix_expires_at', '<', now()->subMinutes(30))->get();

        $card = Order::where('status', 'pending')
            ->where(fn ($q) => $q->whereIn('payment_status', ['refused', 'failed'])->orWhereNull('gateway_uuid'))
            ->where('updated_at', '<', now()->subHours(6))->get();

        $count = 0;
        foreach ($pix->merge($card)->unique('id') as $order) {
            try {
                if ($order->gateway_uuid) {
                    $tx = $gateway->getTransaction($order->gateway_uuid);
                    $status = $tx['status'] ?? 'PENDENTE';
                    if ($status === 'PAGO') {
                        $updater->apply($order, $status, 'expire-check');

                        continue;
                    }
                }
                $updater->apply($order, 'CANCELADO', 'expire');
                $count++;
            } catch (\Throwable $e) {
                report($e);
                $this->warn("Pedido #{$order->number}: {$e->getMessage()}");
            }
        }

        $this->info("$count pedido(s) cancelado(s).");

        return self::SUCCESS;
    }
}
