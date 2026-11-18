<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Checkout\CheckoutService;
use App\Services\Payments\CheckoutPayment;
use App\Services\Payments\GatewayException;
use App\Services\Payments\NixfyGateway;
use App\Services\Payments\PaymentStatusUpdater;
use App\Support\Br;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Página pública do pedido (link no e-mail e após o checkout): PIX, status ao vivo, rastreio e nova tentativa.
 * Acesso por token UUID não sequencial — sem login.
 */
class OrderStatusController extends Controller
{
    public function show(string $token)
    {
        $order = Order::where('public_token', $token)->with('items.variant.product', 'discounts')->firstOrFail();

        // Compra no navegador com o mesmo event_id enviado pelo servidor (deduplicação no Meta/TikTok)
        $purchase = [
            'event_id' => $order->event_id,
            'transaction_id' => $order->number,
            'value' => $order->total,
            'items' => $order->items->map(fn ($i) => [
                'variant_id' => $i->variant_id,
                'content_id' => $i->variant ? \App\Services\Tracking\ContentId::for($i->variant) : (string) $i->variant_id,
                'name' => $i->title,
                'price' => (int) round(($i->unit_price * $i->quantity - $i->discount) / max(1, $i->quantity)),
                'quantity' => $i->quantity,
            ])->values()->all(),
        ];

        return view('store.order', [
            'order' => $order,
            'purchase' => $purchase,
            'trackOrder' => $order,
            'canRetry' => $this->canRetry($order),
            'plan' => installment_plan(max(1, $order->total - $order->interest_total)),
        ]);
    }

    /** Consultado a cada poucos segundos pela página enquanto o PIX não é pago. */
    public function status(string $token, NixfyGateway $gateway, PaymentStatusUpdater $updater)
    {
        $order = Order::where('public_token', $token)->firstOrFail();

        // Plano B se o webhook atrasar: reconsulta a Nixfy no máximo a cada 15s
        if (in_array($order->payment_status, ['pending', 'processing']) && $order->gateway_uuid
            && (! $order->last_gateway_check_at || $order->last_gateway_check_at->lt(now()->subSeconds(15)))) {
            $order->forceFill(['last_gateway_check_at' => now()])->saveQuietly();
            try {
                $tx = $gateway->getTransaction($order->gateway_uuid);
                $order = $updater->apply($order, $tx['status'] ?? 'PENDENTE', 'polling', ['fee' => $tx['systemFee'] ?? null]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'paid' => $order->isPaid(),
        ]);
    }

    public function retry(Request $request, string $token, CheckoutService $checkout, CheckoutPayment $payment)
    {
        $order = Order::where('public_token', $token)->firstOrFail();
        if (! $this->canRetry($order)) {
            return response()->json(['error' => 'Este pedido não aceita nova tentativa de pagamento.', 'redirect' => $order->publicUrl()], 422);
        }

        $method = $request->input('payment_method') === 'credit_card' ? 'credit_card' : 'pix';
        $card = null;
        if ($method === 'credit_card') {
            $request->merge(['card_number' => Br::digits($request->input('card_number'))]);
            $c = $request->validate([
                'card_number' => ['required', 'digits_between:13,19'],
                'card_holder' => ['required', 'string', 'max:60'],
                'card_exp_month' => ['required', 'integer', 'between:1,12'],
                'card_exp_year' => ['required', 'integer'],
                'card_cvv' => ['required', 'digits_between:3,4'],
                'installments' => ['nullable', 'integer', 'min:1', 'max:12'],
            ]);
            if (! Br::luhn($c['card_number'])) {
                throw ValidationException::withMessages(['card_number' => 'Número do cartão inválido.']);
            }
            $card = ['number' => $c['card_number'], 'holder_name' => $c['card_holder'], 'exp_month' => $c['card_exp_month'],
                'exp_year' => (int) $c['card_exp_year'] < 100 ? 2000 + (int) $c['card_exp_year'] : (int) $c['card_exp_year'], 'cvv' => $c['card_cvv']];
        }

        $order = $checkout->reprice($order, $method, (int) $request->input('installments', 1));

        try {
            if ($method === 'credit_card') {
                $payment->card($order, $card, $request->ip());
            } else {
                $order = $payment->pix($order);
                $id = $order->id;
                dispatch(fn () => app(\App\Services\Mail\OrderEmails::class)->pix(Order::find($id)))->afterResponse();
            }
        } catch (GatewayException $e) {
            return response()->json(['error' => $e->getMessage()], 402);
        }

        return response()->json(['redirect' => $order->publicUrl()]);
    }

    /** Rastrear pedido: nº + e-mail → página do pedido. */
    public function track(Request $request)
    {
        $data = $request->validate(['number' => ['required', 'string', 'max:20'], 'email' => ['required', 'email']]);
        $order = Order::where('number', (int) preg_replace('/\D/', '', $data['number']))
            ->where('customer_email', mb_strtolower(trim($data['email'])))->first();

        if (! $order) {
            return back()->withInput()->withErrors(['number' => 'Não encontramos um pedido com esse número e e-mail.']);
        }

        return redirect($order->publicUrl());
    }

    /**
     * Nova tentativa só quando NÃO há cobrança em aberto que possa ser paga em paralelo:
     * cartão recusado/falha, ou pedido sem transação criada (erro de conexão).
     */
    private function canRetry(Order $order): bool
    {
        if ($order->isPaid() || $order->status !== 'pending') {
            return false;
        }

        return in_array($order->payment_status, ['refused', 'failed'], true)
            || ($order->payment_status === 'pending' && ! $order->gateway_uuid);
    }
}
