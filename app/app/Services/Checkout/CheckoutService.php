<?php

namespace App\Services\Checkout;

use App\Events\OrderPlaced;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orquestra o checkout: cotação (frete, descontos, juros) e criação do pedido com reserva de estoque.
 * A cobrança em si fica em CheckoutPayment (Nixfy).
 */
class CheckoutService
{
    public function __construct(private DiscountEngine $discounts) {}

    /** Linhas do carrinho no formato do motor de descontos. */
    public function lines(Cart $cart): array
    {
        $cart->loadMissing('items.variant.product.categories');

        return $cart->items->map(fn ($i) => [
            'variant_id' => $i->variant_id,
            'product_id' => $i->variant->product_id,
            'category_ids' => $i->variant->product->categories->pluck('id')->all(),
            'price' => $i->variant->price,
            'compare' => $i->variant->compare_at_price,
            'qty' => $i->quantity,
            'title' => $i->variant->product->title,
        ])->values()->all();
    }

    /**
     * Resumo de valores para a tela de checkout (recalculado no servidor a cada mudança).
     *
     * @param  array{email?:?string, code?:?string, payment_method?:?string, shipping_method_id?:?int, installments?:?int}  $input
     */
    public function quote(Cart $cart, array $input = []): array
    {
        $lines = $this->lines($cart);
        $subtotal = (int) collect($lines)->sum(fn ($l) => $l['price'] * $l['qty']);
        $method = in_array($input['payment_method'] ?? null, ['pix', 'credit_card'], true) ? $input['payment_method'] : null;

        $methods = ShippingMethod::where('is_active', true)->orderBy('position')->get();
        $selected = $methods->firstWhere('id', (int) ($input['shipping_method_id'] ?? 0)) ?? $methods->first();
        $baseShipping = $selected ? $selected->priceFor($subtotal) : 0;

        $disc = $this->discounts->calculate($lines, [
            'email' => $input['email'] ?? null,
            'code' => $input['code'] ?? null,
            'payment_method' => $method,
            'shipping' => $baseShipping,
        ]);

        // Frete grátis por cupom vale para a modalidade padrão (mais barata); expresso continua cobrando a diferença
        $shippingOptions = $methods->map(function (ShippingMethod $m) use ($subtotal, $disc, $methods) {
            $price = $m->priceFor($subtotal);
            if ($disc['free_shipping']) {
                $cheapest = $methods->min(fn ($x) => $x->priceFor($subtotal));
                $price = max(0, $price - $cheapest);
            }

            return [
                'id' => $m->id,
                'name' => $m->name,
                'description' => $m->description,
                'price' => $price,
                'estimate' => $m->min_days && $m->max_days ? "{$m->min_days} a {$m->max_days} dias úteis" : null,
            ];
        })->values();

        $shipping = $selected ? (int) ($shippingOptions->firstWhere('id', $selected->id)['price'] ?? 0) : 0;
        $beforeInterest = max(0, $subtotal - $disc['items_discount'] + $shipping);

        // Parcelamento sobre o valor já com descontos e frete
        $plan = installment_plan($beforeInterest);
        $installments = $method === 'credit_card' ? max(1, min((int) ($input['installments'] ?? 1), count($plan))) : 1;
        $interest = $method === 'credit_card' && isset($plan[$installments]) ? max(0, $plan[$installments]['total'] - $beforeInterest) : 0;

        // Valor no PIX (para mostrar a vantagem mesmo com cartão selecionado)
        $pixDisc = $method === 'pix' ? $disc : $this->discounts->calculate($lines, ['email' => $input['email'] ?? null, 'code' => $input['code'] ?? null, 'payment_method' => 'pix', 'shipping' => $baseShipping]);
        $pixTotal = max(0, $subtotal - $pixDisc['items_discount'] + $shipping);

        return [
            'lines' => $lines,
            'count' => (int) collect($lines)->sum('qty'),
            'subtotal' => $subtotal,
            'compare_total' => (int) collect($lines)->sum(fn ($l) => ($l['compare'] ?: $l['price']) * $l['qty']),
            'discounts' => $disc['discounts'],
            'line_discounts' => $disc['line_discounts'],
            'discount_total' => $disc['items_discount'],
            'free_shipping' => $disc['free_shipping'],
            'code_status' => $disc['code_status'],
            'code_message' => $disc['code_message'],
            'shipping_options' => $shippingOptions->all(),
            'shipping_method_id' => $selected?->id,
            'shipping' => $shipping,
            'payment_method' => $method,
            'installments' => $installments,
            'installment_plan' => array_values($plan),
            'interest' => $interest,
            'total' => $beforeInterest + $interest,
            'pix_total' => $pixTotal,
        ];
    }

    /**
     * Cria o pedido a partir do carrinho, reservando estoque de forma atômica.
     *
     * @throws CheckoutException
     */
    public function place(Cart $cart, array $data, array $tracking = []): Order
    {
        if ($cart->items()->count() === 0) {
            throw new CheckoutException('Seu carrinho está vazio.');
        }

        $quote = $this->quote($cart, [
            'email' => $data['email'],
            'code' => $data['coupon'] ?? null,
            'payment_method' => $data['payment_method'],
            'shipping_method_id' => $data['shipping_method_id'] ?? null,
            'installments' => $data['installments'] ?? 1,
        ]);
        if ($quote['total'] <= 0) {
            throw new CheckoutException('Não foi possível calcular o total do pedido.');
        }
        if (! empty($data['coupon']) && in_array($quote['code_status'], ['invalid', 'not_eligible'], true)) {
            throw new CheckoutException($quote['code_message']);
        }
        $shippingMethod = ShippingMethod::find($quote['shipping_method_id']);

        return DB::transaction(function () use ($cart, $data, $quote, $tracking, $shippingMethod) {
            $email = mb_strtolower(trim($data['email']));
            $customer = Customer::firstOrNew(['email' => $email]);
            $customer->fill([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'document' => $data['document'],
            ]);
            if (! $customer->exists) {
                $customer->accepts_marketing = (bool) ($data['accepts_marketing'] ?? true);
            }
            $customer->save();

            $address = [
                'zip' => $data['zip'],
                'street' => $data['street'],
                'number' => $data['number'],
                'complement' => $data['complement'] ?? null,
                'neighborhood' => $data['neighborhood'],
                'city' => $data['city'],
                'state' => strtoupper($data['state']),
                'recipient' => $data['name'],
            ];
            $customer->addresses()->updateOrCreate(['zip' => $data['zip'], 'number' => $data['number']], $address + ['is_default' => true]);

            $attr = $tracking['attribution'] ?? ($cart->attribution ?? []);
            $order = Order::create([
                'customer_id' => $customer->id,
                'cart_id' => $cart->id,
                'status' => 'pending',
                'payment_status' => 'pending',
                'customer_name' => $data['name'],
                'customer_email' => $email,
                'customer_phone' => $data['phone'],
                'customer_document' => $data['document'],
                'shipping_address' => $address,
                'subtotal' => $quote['subtotal'],
                'discount_total' => $quote['discount_total'],
                'shipping_total' => $quote['shipping'],
                'interest_total' => $quote['interest'],
                'total' => $quote['total'],
                'discount_codes' => collect($quote['discounts'])->pluck('code')->filter()->implode(', ') ?: null,
                'shipping_method' => $shippingMethod?->name,
                'shipping_method_id' => $shippingMethod?->id,
                'shipping_estimate' => collect($quote['shipping_options'])->firstWhere('id', $shippingMethod?->id)['estimate'] ?? null,
                'payment_method' => $data['payment_method'],
                'installments' => $quote['installments'],
                'attribution' => $attr ?: null,
                'utm_source' => $attr['utm_source'] ?? null,
                'utm_medium' => $attr['utm_medium'] ?? null,
                'utm_campaign' => $attr['utm_campaign'] ?? null,
                'utm_content' => $attr['utm_content'] ?? null,
                'utm_term' => $attr['utm_term'] ?? null,
                'fbp' => $tracking['fbp'] ?? null,
                'fbc' => $tracking['fbc'] ?? null,
                'client_ip' => $tracking['ip'] ?? null,
                'user_agent' => isset($tracking['user_agent']) ? Str::limit($tracking['user_agent'], 500, '') : null,
                'visitor_id' => isset($tracking['visitor_id']) && Str::isUuid($tracking['visitor_id']) ? $tracking['visitor_id'] : null,
                'ttclid' => $tracking['ttclid'] ?? ($attr['ttclid'] ?? null),
                'ttp' => $tracking['ttp'] ?? null,
                'ga_client_id' => $tracking['ga_client_id'] ?? null,
                'page_url' => isset($tracking['page_url']) ? Str::limit($tracking['page_url'], 500, '') : null,
            ]);

            // Itens + reserva de estoque (lockForUpdate evita vender a mesma peça duas vezes no pico)
            $cart->loadMissing('items.variant.product.images', 'items.variant.image');
            foreach ($cart->items as $item) {
                $v = $item->variant;
                $p = $v->product;
                if ($p->status !== 'active' || ! $v->is_active) {
                    throw new CheckoutException("O produto “{$p->title}” não está mais disponível. Remova-o do carrinho para continuar.");
                }
                if ($p->track_stock && ! $v->adjustStock(-$item->quantity, 'order', $order)) {
                    $label = $v->title !== 'Padrão' ? " ({$v->title})" : '';
                    throw new CheckoutException("“{$p->title}{$label}” esgotou ou não tem a quantidade pedida. Ajuste o carrinho para continuar.");
                }
                $lineDiscount = (int) ($quote['line_discounts'][$v->id] ?? 0);
                $img = $v->image ?? $p->images->first();
                $order->items()->create([
                    'product_id' => $p->id,
                    'variant_id' => $v->id,
                    'title' => $p->title,
                    'variant_title' => $v->title === 'Padrão' ? null : $v->title,
                    'sku' => $v->sku,
                    'supplier_sku' => $v->supplier_sku,
                    'image' => $img?->path,
                    'unit_price' => $v->price,
                    'compare_at_price' => $v->compare_at_price,
                    'unit_cost' => $v->cost,
                    'quantity' => $item->quantity,
                    'discount' => $lineDiscount,
                    'total' => $v->price * $item->quantity - $lineDiscount,
                    'properties' => $this->properties($p, $v),
                ]);
            }

            foreach ($quote['discounts'] as $d) {
                $order->discounts()->create([
                    'discount_id' => $d['discount_id'],
                    'title' => $d['title'],
                    'code' => $d['code'],
                    'amount' => $d['free_shipping'] ? max($d['amount'], 0) : $d['amount'],
                ]);
            }

            $cart->forceFill([
                'email' => $email,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'customer_id' => $customer->id,
                'checkout_started_at' => $cart->checkout_started_at ?? now(),
            ])->save();

            $order->log('created', 'Pedido criado no checkout', ['payment_method' => $data['payment_method'], 'total' => $order->total]);

            DB::afterCommit(fn () => event(new OrderPlaced($order)));

            return $order->load('items');
        });
    }

    /**
     * Recalcula a parte do total que depende da forma de pagamento (desconto PIX e juros do cartão)
     * quando a cliente tenta pagar de novo com outro meio. Descontos de produto/cupom e frete não mudam.
     */
    public function reprice(Order $order, string $method, int $installments = 1): Order
    {
        return DB::transaction(function () use ($order, $method, $installments) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('discounts', 'items')->first();

            $paymentDiscountIds = \App\Models\Discount::where('type', 'payment_method')->pluck('id')->all();
            $isPayment = fn ($d) => $d->discount_id === null || in_array($d->discount_id, $paymentDiscountIds);
            $order->discounts->filter($isPayment)->each->delete();
            $base = $order->discounts->reject($isPayment)->sum('amount');

            $remaining = max(0, $order->subtotal - $base);
            foreach ($this->discounts->paymentDiscounts($method) as [$id, $title, $percent]) {
                $amount = (int) floor($remaining * $percent / 100);
                if ($amount > 0) {
                    $order->discounts()->create(['discount_id' => $id, 'title' => $title, 'code' => null, 'amount' => $amount]);
                    $remaining -= $amount;
                }
            }
            $discountTotal = $order->subtotal - $remaining;
            $beforeInterest = $remaining + $order->shipping_total;

            $plan = installment_plan($beforeInterest);
            $n = $method === 'credit_card' ? max(1, min($installments, count($plan))) : 1;
            $interest = $method === 'credit_card' && isset($plan[$n]) ? max(0, $plan[$n]['total'] - $beforeInterest) : 0;

            // Rateia o desconto total entre os itens de novo
            $lines = $order->items->map(fn ($i) => ['variant_id' => $i->id, 'price' => $i->unit_price, 'qty' => $i->quantity]);
            $spread = $this->discounts->spread($lines, $discountTotal);
            foreach ($order->items as $i) {
                $d = (int) ($spread[$i->id] ?? 0);
                $i->update(['discount' => $d, 'total' => $i->unit_price * $i->quantity - $d]);
            }

            $order->forceFill([
                'payment_method' => $method,
                'installments' => $n,
                'discount_total' => $discountTotal,
                'interest_total' => $interest,
                'total' => $beforeInterest + $interest,
                'payment_status' => 'pending',
                'gateway_uuid' => null,
                'pix_code' => null,
                'pix_qr_base64' => null,
                'pix_expires_at' => null,
            ])->save();
            $order->log('payment', 'Nova tentativa de pagamento: '.($method === 'pix' ? 'PIX' : "cartão {$n}x").' — total '.money($order->total));

            return $order->fresh(['items', 'discounts']);
        });
    }

    private function properties($product, ProductVariant $v): ?array
    {
        $opts = $product->options()->orderBy('position')->pluck('name')->all();
        $vals = [$v->option1, $v->option2, $v->option3];
        $props = [];
        foreach ($opts as $i => $name) {
            if (! empty($vals[$i])) {
                $props[$name] = $vals[$i];
            }
        }

        return $props ?: null;
    }
}
