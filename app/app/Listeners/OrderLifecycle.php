<?php

namespace App\Listeners;

use App\Events\OrderCanceled;
use App\Events\OrderPaid;
use App\Models\Cart;
use App\Models\Discount;
use App\Models\Product;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Efeitos colaterais dos eventos do pedido que não dependem de serviços externos.
 * (E-mails → Fase 6; Meta CAPI/TikTok/GA4 → Fase 4 — entram como listeners próprios na fila.)
 */
class OrderLifecycle
{
    public function paid(OrderPaid $event): void
    {
        $order = $event->order->loadMissing('items', 'discounts', 'customer');

        DB::transaction(function () use ($order) {
            // "Mais vendidos" e selo "X vendidos" usam vendas reais
            foreach ($order->items->groupBy('product_id') as $productId => $items) {
                if ($productId) {
                    Product::whereKey($productId)->increment('sales_count', (int) $items->sum('quantity'));
                }
            }
            foreach ($order->discounts->pluck('discount_id')->filter()->unique() as $id) {
                Discount::whereKey($id)->increment('used_count');
            }
        });

        $order->customer?->refreshStats();

        if ($order->cart_id && ($cart = Cart::find($order->cart_id))) {
            $cart->forceFill([
                'status' => 'converted',
                'recovered_at' => $cart->recovery_emails_sent > 0 ? now() : $cart->recovered_at,
            ])->save();
        }
    }

    public function canceled(OrderCanceled $event): void
    {
        $event->order->releaseStock('cancel');
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPaid::class => 'paid',
            OrderCanceled::class => 'canceled',
        ];
    }
}
