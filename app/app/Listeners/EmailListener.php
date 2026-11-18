<?php

namespace App\Listeners;

use App\Events\OrderCanceled;
use App\Events\OrderDelivered;
use App\Events\OrderPaid;
use App\Events\OrderPaymentRefused;
use App\Events\OrderPlaced;
use App\Events\OrderRefunded;
use App\Events\OrderShipped;
use App\Services\Mail\OrderEmails;
use Illuminate\Events\Dispatcher;

/**
 * E-mails da cliente em cada evento do pedido. Enviados logo depois da resposta HTTP
 * (não atrasam o checkout/webhook e não dependem de worker de fila na hospedagem compartilhada).
 */
class EmailListener
{
    private function later(callable $fn): void
    {
        dispatch(function () use ($fn) {
            $fn(app(OrderEmails::class));
        })->afterResponse();
    }

    public function placed(OrderPlaced $e): void
    {
        $id = $e->order->id;
        $this->later(function (OrderEmails $m) use ($id) {
            $order = \App\Models\Order::find($id);
            $m->placed($order);
            $m->lowStock($order);
        });
    }

    public function paid(OrderPaid $e): void
    {
        $id = $e->order->id;
        $this->later(fn (OrderEmails $m) => $m->paid(\App\Models\Order::find($id)));
    }

    public function refused(OrderPaymentRefused $e): void
    {
        $id = $e->order->id;
        $this->later(fn (OrderEmails $m) => $m->refused(\App\Models\Order::find($id)));
    }

    public function shipped(OrderShipped $e): void
    {
        $id = $e->order->id;
        $this->later(fn (OrderEmails $m) => $m->shipped(\App\Models\Order::find($id)));
    }

    public function delivered(OrderDelivered $e): void
    {
        $id = $e->order->id;
        $this->later(fn (OrderEmails $m) => $m->delivered(\App\Models\Order::find($id)));
    }

    public function canceled(OrderCanceled $e): void
    {
        $id = $e->order->id;
        $this->later(fn (OrderEmails $m) => $m->canceled(\App\Models\Order::find($id)));
    }

    public function refunded(OrderRefunded $e): void
    {
        $id = $e->order->id;
        $this->later(fn (OrderEmails $m) => $m->refunded(\App\Models\Order::find($id)));
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPlaced::class => 'placed',
            OrderPaid::class => 'paid',
            OrderPaymentRefused::class => 'refused',
            OrderShipped::class => 'shipped',
            OrderDelivered::class => 'delivered',
            OrderCanceled::class => 'canceled',
            OrderRefunded::class => 'refunded',
        ];
    }
}
