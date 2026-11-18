<?php

namespace App\Listeners;

use App\Events\OrderCanceled;
use App\Events\OrderPaid;
use App\Events\OrderPaymentRefused;
use App\Events\OrderPlaced;
use App\Events\OrderRefunded;
use App\Services\Tracking\TrackingService;
use Illuminate\Events\Dispatcher;

/**
 * Pedido pago → Purchase no servidor (Meta CAPI, TikTok, GA4) logo após responder ao webhook.
 * Toda mudança de status → UTMify.
 * Rodar "depois da resposta" mantém o webhook rápido (a Nixfy aborta em 10s).
 */
class TrackingListener
{
    public function __construct(private TrackingService $tracking) {}

    public function paid(OrderPaid $e): void
    {
        $order = $e->order;
        dispatch(function () use ($order) {
            app(TrackingService::class)->sendPurchase($order->fresh());
            app(TrackingService::class)->sendUtmify($order->fresh());
        })->afterResponse();
    }

    public function statusChanged(OrderPlaced|OrderCanceled|OrderPaymentRefused|OrderRefunded $e): void
    {
        $order = $e->order;
        dispatch(fn () => app(TrackingService::class)->sendUtmify($order->fresh()))->afterResponse();
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPaid::class => 'paid',
            OrderPlaced::class => 'statusChanged',
            OrderCanceled::class => 'statusChanged',
            OrderPaymentRefused::class => 'statusChanged',
            OrderRefunded::class => 'statusChanged',
        ];
    }
}
