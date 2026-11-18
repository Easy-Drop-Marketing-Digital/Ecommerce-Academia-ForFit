<?php

namespace App\Filament\Resources\Discounts\Pages;

use App\Filament\Support\Money;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;

/** Conversões de formulário compartilhadas pelas páginas de criar/editar desconto. */
trait SyncsDiscountTargets
{
    protected function normalize(array $data): array
    {
        $raw = $this->form->getRawState();
        if (($data['type'] ?? null) === 'fixed_amount') {
            $data['value'] = Money::toCents($raw['value_money'] ?? 0) ?? 0;
        }
        if (in_array($data['type'] ?? null, ['free_shipping', 'buy_x_get_y', 'tiered', 'bundle'], true)) {
            $data['value'] = 0;
        }
        if (! in_array($data['type'] ?? null, ['percentage', 'fixed_amount', 'buy_x_get_y', 'tiered'], true)) {
            $data['applies_to'] = 'all';
        }

        return $data;
    }

    protected function syncTargets(Discount $discount): void
    {
        $raw = $this->form->getRawState();
        $discount->targets()->delete();
        $ids = match ($discount->applies_to) {
            'categories' => [Category::class, $raw['target_categories'] ?? []],
            'products' => [Product::class, $raw['target_products'] ?? []],
            default => [null, []],
        };
        foreach ($ids[1] as $id) {
            $discount->targets()->create(['target_type' => $ids[0], 'target_id' => (int) $id]);
        }
    }

    protected function fillExtras(array $data, Discount $discount): array
    {
        if ($discount->type === 'fixed_amount') {
            $data['value_money'] = $discount->value;
        }
        $data['target_categories'] = $discount->targets()->where('target_type', Category::class)->pluck('target_id')->all();
        $data['target_products'] = $discount->targets()->where('target_type', Product::class)->pluck('target_id')->all();

        return $data;
    }
}
