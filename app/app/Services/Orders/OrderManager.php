<?php

namespace App\Services\Orders;

use App\Events\OrderCanceled;
use App\Events\OrderDelivered;
use App\Events\OrderRefunded;
use App\Events\OrderShipped;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ações de operação do pedido feitas pelo admin. Cada uma registra na linha do tempo e dispara o evento
 * correspondente (e-mail à cliente na Fase 6, UTMify, etc.).
 */
class OrderManager
{
    public function markProcessing(Order $order, ?int $userId = null): Order
    {
        $this->requirePaid($order);
        $order->update(['status' => 'processing']);
        $order->log('status', 'Pedido em separação', [], $userId);

        return $order;
    }

    public function markShipped(Order $order, string $trackingCode, ?string $carrier = null, ?string $trackingUrl = null, ?int $userId = null): Order
    {
        $this->requirePaid($order);
        $trackingUrl = $trackingUrl ?: $this->defaultTrackingUrl($trackingCode, $carrier);
        $order->update([
            'status' => 'shipped',
            'fulfillment_status' => 'fulfilled',
            'tracking_code' => strtoupper(trim($trackingCode)),
            'carrier' => $carrier,
            'tracking_url' => $trackingUrl,
            'shipped_at' => $order->shipped_at ?? now(),
        ]);
        $order->log('shipped', "Enviado — rastreio {$order->tracking_code}".($carrier ? " ($carrier)" : ''), [], $userId);
        event(new OrderShipped($order));

        return $order;
    }

    public function markDelivered(Order $order, ?int $userId = null): Order
    {
        $this->requirePaid($order);
        $order->update(['status' => 'delivered', 'delivered_at' => now()]);
        $order->log('delivered', 'Pedido entregue', [], $userId);
        event(new OrderDelivered($order));

        return $order;
    }

    /**
     * Cancela um pedido. Se já foi pago, marca como reembolsado — o estorno em si é feito no painel da Nixfy
     * (a API pública não expõe estorno); quando a Nixfy confirmar, o webhook mantém o status em "refunded".
     */
    public function cancel(Order $order, string $reason, bool $restock = true, ?int $userId = null): Order
    {
        if (in_array($order->status, ['canceled', 'refunded'], true)) {
            throw new RuntimeException('Este pedido já está cancelado.');
        }

        DB::transaction(function () use ($order, $reason, $userId) {
            if ($order->isPaid()) {
                $order->update(['status' => 'refunded', 'canceled_at' => now()]);
                $order->log('refund', 'Cancelado com reembolso: '.$reason.' — conclua o estorno no painel da Nixfy.', [], $userId);
            } else {
                $order->update(['status' => 'canceled', 'payment_status' => 'canceled', 'canceled_at' => now()]);
                $order->log('cancel', 'Cancelado: '.$reason, [], $userId);
            }
        });

        if ($restock) {
            $order->releaseStock('cancel');
        }
        event($order->status === 'refunded' ? new OrderRefunded($order) : new OrderCanceled($order));

        return $order;
    }

    public function addNote(Order $order, string $note, ?int $userId = null): void
    {
        $order->log('note', $note, [], $userId);
    }

    /**
     * CSV para enviar ao fornecedor (dropshipping): uma linha por item, com endereço completo.
     * Separador ";" e BOM UTF-8 para abrir certo no Excel em português.
     */
    public function supplierCsv(Collection $orders): string
    {
        $orders = \Illuminate\Database\Eloquent\Collection::make($orders)->loadMissing('items');
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Pedido', 'Data', 'Status', 'Produto', 'Variação', 'Código no fornecedor', 'Qtd', 'Cliente', 'CPF', 'Telefone', 'E-mail',
            'CEP', 'Endereço', 'Número', 'Complemento', 'Bairro', 'Cidade', 'UF', 'Frete', 'Rastreio'], ';');
        foreach ($orders as $o) {
            $a = $o->shipping_address ?? [];
            foreach ($o->items as $i) {
                fputcsv($out, [
                    $o->number, $o->created_at?->format('d/m/Y H:i'), $o->statusLabel(), $i->title, $i->variant_title, $i->supplier_sku ?: $i->sku, $i->quantity,
                    $o->customer_name, $o->customer_document, $o->customer_phone, $o->customer_email,
                    $a['zip'] ?? '', $a['street'] ?? '', $a['number'] ?? '', $a['complement'] ?? '', $a['neighborhood'] ?? '', $a['city'] ?? '', $a['state'] ?? '',
                    $o->shipping_method, $o->tracking_code,
                ], ';');
            }
        }
        rewind($out);

        return stream_get_contents($out);
    }

    private function requirePaid(Order $order): void
    {
        if (! $order->isPaid() || in_array($order->status, ['canceled', 'refunded'], true)) {
            throw new RuntimeException('Só é possível atualizar o envio de pedidos pagos.');
        }
    }

    private function defaultTrackingUrl(string $code, ?string $carrier): ?string
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $code)) {
            return 'https://rastreamento.correios.com.br/app/index.php?objetos='.$code; // padrão Correios/UPU
        }

        return 'https://www.17track.net/pt/track?nums='.urlencode($code); // internacional / transportadoras
    }
}
