<?php

namespace App\Services\Checkout;

use App\Models\Discount;
use App\Models\Order;
use App\Support\StoreSettings;
use Illuminate\Support\Collection;

/**
 * Calcula descontos do carrinho/checkout.
 *
 * Regras de combinação (simples e previsíveis para o lojista):
 *  - Descontos marcados como "combinável" somam entre si.
 *  - Dos NÃO combináveis (automáticos + cupom digitado), vale só o de MAIOR valor.
 *  - Desconto por forma de pagamento (ex.: PIX) é aplicado por último, sobre o valor já descontado.
 *  - O total de descontos nunca ultrapassa o subtotal dos produtos.
 *
 * Linhas de entrada: [['variant_id','product_id','category_ids'=>[],'price','qty','title']]
 */
class DiscountEngine
{
    /**
     * @param  array{email?:?string, code?:?string, payment_method?:?string, shipping?:int}  $ctx
     */
    public function calculate(array $lines, array $ctx = []): array
    {
        $lines = collect($lines);
        $subtotal = (int) $lines->sum(fn ($l) => $l['price'] * $l['qty']);
        $code = isset($ctx['code']) ? mb_strtoupper(trim((string) $ctx['code'])) : '';
        $result = [
            'discounts' => [],
            'items_discount' => 0,
            'free_shipping' => false,
            'line_discounts' => [],
            'code_status' => null,
            'code_message' => null,
        ];
        if ($subtotal <= 0) {
            return $result;
        }

        // 1) Candidatos: automáticos ativos + cupom digitado
        $candidates = Discount::live()->whereNull('code')->where('type', '!=', 'payment_method')->with('targets')->get();
        $codeDiscount = null;
        if ($code !== '') {
            $codeDiscount = Discount::with('targets')->whereRaw('UPPER(code) = ?', [$code])->first();
            if (! $codeDiscount || ! $codeDiscount->isLive() || $codeDiscount->type === 'payment_method') {
                $result['code_status'] = 'invalid';
                $result['code_message'] = 'Cupom inválido ou expirado.';
                $codeDiscount = null;
            } elseif (! $this->customerCanUse($codeDiscount, $ctx['email'] ?? null)) {
                $result['code_status'] = 'not_eligible';
                $result['code_message'] = 'Você já utilizou este cupom.';
                $codeDiscount = null;
            } else {
                $candidates->push($codeDiscount);
            }
        }

        // 2) Valor de cada candidato isoladamente
        $evaluated = $candidates->map(fn (Discount $d) => $this->evaluate($d, $lines, $subtotal, (int) ($ctx['shipping'] ?? 0)))
            ->filter(fn ($e) => $e['amount'] > 0 || $e['free_shipping']);

        if ($codeDiscount && ! $evaluated->firstWhere('discount.id', $codeDiscount->id)) {
            $result['code_status'] = 'not_eligible';
            $result['code_message'] = $this->unmetMessage($codeDiscount, $lines, $subtotal);
        }

        // 3) Combinação
        $combinable = $evaluated->filter(fn ($e) => $e['discount']->combinable);
        $best = $evaluated->reject(fn ($e) => $e['discount']->combinable)
            ->sortByDesc(fn ($e) => $e['amount'] + ($e['free_shipping'] ? (int) ($ctx['shipping'] ?? 0) : 0))->first();
        $applied = $combinable->values();
        if ($best) {
            $applied->push($best);
        }

        if ($codeDiscount && $result['code_status'] === null) {
            $wasApplied = $applied->contains(fn ($e) => $e['discount']->id === $codeDiscount->id);
            $result['code_status'] = $wasApplied ? 'applied' : 'superseded';
            $result['code_message'] = $wasApplied ? 'Cupom aplicado!' : 'Você já tem um desconto melhor aplicado.';
        }

        // 4) Consolida (limitado ao subtotal) e distribui por linha
        $remaining = $subtotal;
        foreach ($applied as $e) {
            $amount = min($e['amount'], $remaining);
            $remaining -= $amount;
            $result['free_shipping'] = $result['free_shipping'] || $e['free_shipping'];
            $result['discounts'][] = [
                'discount_id' => $e['discount']->id,
                'title' => $e['discount']->title,
                'code' => $e['discount']->code,
                'type' => $e['discount']->type,
                'amount' => $amount,
                'free_shipping' => $e['free_shipping'],
            ];
            foreach ($e['lines'] as $vid => $cents) {
                $result['line_discounts'][$vid] = ($result['line_discounts'][$vid] ?? 0) + $cents;
            }
        }

        // 5) Desconto pela forma de pagamento, sobre o valor já descontado
        $method = $ctx['payment_method'] ?? null;
        if ($method && $remaining > 0) {
            foreach ($this->paymentDiscounts($method) as [$id, $title, $percent]) {
                $amount = (int) floor($remaining * $percent / 100);
                if ($amount <= 0) {
                    continue;
                }
                $remaining -= $amount;
                $result['discounts'][] = ['discount_id' => $id, 'title' => $title, 'code' => null, 'type' => 'payment_method', 'amount' => $amount, 'free_shipping' => false];
                foreach ($this->spread($lines, $amount) as $vid => $cents) {
                    $result['line_discounts'][$vid] = ($result['line_discounts'][$vid] ?? 0) + $cents;
                }
            }
        }

        $result['items_discount'] = $subtotal - $remaining;

        return $result;
    }

    /** Percentuais de desconto por forma de pagamento: configuração rápida (Admin → Nixfy) + descontos cadastrados. */
    public function paymentDiscounts(string $method): array
    {
        $out = [];
        if ($method === 'pix' && ($p = (float) str_replace(',', '.', (string) StoreSettings::get('nixfy.pix_discount_percent', 0))) > 0) {
            $out[] = [null, "Desconto no PIX ({$p}%)", $p];
        }
        foreach (Discount::live()->where('type', 'payment_method')->whereNull('code')->get() as $d) {
            if (($d->rules['method'] ?? null) === $method) {
                $out[] = [$d->id, $d->title, (float) $d->value];
            }
        }

        return $out;
    }

    private function evaluate(Discount $d, Collection $lines, int $subtotal, int $shipping): array
    {
        $none = ['discount' => $d, 'amount' => 0, 'free_shipping' => false, 'lines' => []];
        $eligibleIds = $d->eligibleProductIds();
        $eligible = $eligibleIds === null ? $lines : $lines->filter(fn ($l) => in_array($l['product_id'], $eligibleIds));
        $eligibleSubtotal = (int) $eligible->sum(fn ($l) => $l['price'] * $l['qty']);
        $eligibleQty = (int) $eligible->sum('qty');

        if ($d->min_subtotal && $subtotal < $d->min_subtotal) {
            return $none;
        }
        if ($d->min_quantity && $eligibleQty < $d->min_quantity) {
            return $none;
        }
        if ($eligible->isEmpty()) {
            return $none;
        }

        $rules = $d->rules ?? [];

        switch ($d->type) {
            case 'percentage':
                $amount = (int) floor($eligibleSubtotal * min(100, $d->value) / 100);

                return ['discount' => $d, 'amount' => $amount, 'free_shipping' => false, 'lines' => $this->spread($eligible, $amount)];

            case 'fixed_amount':
                $amount = min($d->value, $eligibleSubtotal);

                return ['discount' => $d, 'amount' => $amount, 'free_shipping' => false, 'lines' => $this->spread($eligible, $amount)];

            case 'free_shipping':
                return ['discount' => $d, 'amount' => 0, 'free_shipping' => true, 'lines' => []];

            case 'tiered':
                $tiers = collect($rules['tiers'] ?? [])->sortByDesc('min_qty');
                $tier = $tiers->first(fn ($t) => $eligibleQty >= (int) $t['min_qty']);
                if (! $tier) {
                    return $none;
                }
                $amount = (int) floor($eligibleSubtotal * min(100, (float) $tier['percent']) / 100);

                return ['discount' => $d, 'amount' => $amount, 'free_shipping' => false, 'lines' => $this->spread($eligible, $amount)];

            case 'buy_x_get_y':
                $buy = max(1, (int) ($rules['buy_qty'] ?? 1));
                $get = max(1, (int) ($rules['get_qty'] ?? 1));
                $pct = min(100, (float) ($rules['get_percent'] ?? 100));
                // Unidades elegíveis do mais caro para o mais barato; em cada grupo de (X+Y) as Y mais baratas ganham o desconto
                $units = $eligible->flatMap(fn ($l) => array_fill(0, $l['qty'], ['vid' => $l['variant_id'], 'price' => $l['price']]))
                    ->sortByDesc('price')->values();
                $groups = intdiv($units->count(), $buy + $get);
                if ($groups === 0) {
                    return $none;
                }
                $free = $units->slice($units->count() - $groups * $get)->take($groups * $get); // as mais baratas
                $perLine = [];
                $amount = 0;
                foreach ($free as $u) {
                    $c = (int) floor($u['price'] * $pct / 100);
                    $perLine[$u['vid']] = ($perLine[$u['vid']] ?? 0) + $c;
                    $amount += $c;
                }

                return ['discount' => $d, 'amount' => $amount, 'free_shipping' => false, 'lines' => $perLine];

            case 'bundle':
                // rules: {items:[{product_id, qty}], price?: centavos do combo, percent?: % sobre o combo}
                $items = collect($rules['items'] ?? []);
                if ($items->isEmpty()) {
                    return $none;
                }
                $byProduct = $lines->groupBy('product_id');
                $sets = $items->map(function ($it) use ($byProduct) {
                    $have = (int) ($byProduct->get($it['product_id'])?->sum('qty') ?? 0);

                    return intdiv($have, max(1, (int) $it['qty']));
                })->min();
                if ($sets < 1) {
                    return $none;
                }
                // valor cheio de um combo (usa o preço mais baixo de cada produto no carrinho)
                $setValue = (int) $items->sum(fn ($it) => $byProduct->get($it['product_id'])->min('price') * (int) $it['qty']);
                if (isset($rules['price'])) {
                    $perSet = max(0, $setValue - (int) $rules['price']);
                } else {
                    $perSet = (int) floor($setValue * min(100, (float) ($rules['percent'] ?? 0)) / 100);
                }
                $amount = $perSet * $sets;
                $bundleLines = $lines->filter(fn ($l) => $items->pluck('product_id')->contains($l['product_id']));

                return ['discount' => $d, 'amount' => $amount, 'free_shipping' => false, 'lines' => $this->spread($bundleLines, $amount)];
        }

        return $none;
    }

    /** Rateio proporcional de um valor entre as linhas (centavo a centavo, sem sobra). */
    public function spread(Collection $lines, int $amount): array
    {
        $total = (int) $lines->sum(fn ($l) => $l['price'] * $l['qty']);
        if ($amount <= 0 || $total <= 0) {
            return [];
        }
        $out = [];
        $left = $amount;
        $last = $lines->keys()->last();
        foreach ($lines as $k => $l) {
            $share = $k === $last ? $left : (int) floor($amount * $l['price'] * $l['qty'] / $total);
            $out[$l['variant_id']] = ($out[$l['variant_id']] ?? 0) + $share;
            $left -= $share;
        }

        return $out;
    }

    private function customerCanUse(Discount $d, ?string $email): bool
    {
        if (! $d->usage_limit_per_customer || ! $email) {
            return true;
        }
        $used = Order::where('customer_email', mb_strtolower($email))
            ->whereNotNull('paid_at')
            ->whereHas('discounts', fn ($q) => $q->where('discount_id', $d->id))
            ->count();

        return $used < $d->usage_limit_per_customer;
    }

    private function unmetMessage(Discount $d, Collection $lines, int $subtotal): string
    {
        if ($d->min_subtotal && $subtotal < $d->min_subtotal) {
            return 'Este cupom vale para compras acima de '.money($d->min_subtotal).'.';
        }
        if ($d->min_quantity) {
            return "Este cupom vale a partir de {$d->min_quantity} peças.";
        }

        return 'Este cupom não se aplica aos produtos do seu carrinho.';
    }
}
