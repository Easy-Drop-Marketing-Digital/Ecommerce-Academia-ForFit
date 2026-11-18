<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dados prontos para o card de produto (vitrines, coleção, busca, relacionados).
 */
class ProductCard
{
    /** Eager-load mínimo para renderizar cards sem N+1. */
    public static function scope(Builder $q): Builder
    {
        return $q->active()
            ->with(['images' => fn ($i) => $i->orderBy('position')->limit(2), 'brand:id,name,slug'])
            ->withCount(['variants as variants_count' => fn ($v) => $v->where('is_active', true)])
            ->withSum(['variants as stock_total' => fn ($v) => $v->where('is_active', true)], 'stock');
    }

    /**
     * Larguras para o navegador escolher (celular, retina, desktop largo).
     * Vale para fotos locais em vários tamanhos (…-r400.webp) e para o CDN da Shopify; senão, null.
     */
    public static function srcset(string $path, array $widths = [400, 600, 900]): ?string
    {
        $multi = preg_match('/-r\d+\.webp$/', $path) || str_contains($path, 'cdn.shopify.com') || str_contains($path, '/cdn/shop/');

        return $multi ? collect($widths)->map(fn ($w) => img_url($path, $w)." {$w}w")->implode(', ') : null;
    }

    public static function make(Product $p): array
    {
        $price = (int) $p->min_price;
        $compare = $p->max_compare_price;
        $images = $p->images->take(2)->values();
        // maior parcela SEM juros (ex.: 6x) — é o que o cliente procura; senão, a maior com juros
        $plan = installment_plan($price);
        $free = collect($plan)->where('interest', false)->last();
        $inst = $free && $free['n'] > 1 ? $free : max_installment($price);

        return [
            'id' => $p->id,
            'title' => $p->title,
            'brand' => $p->brand?->name,
            'pix_price' => pix_price($price),
            'url' => route('product.show', $p->slug),
            'image' => $images->get(0) ? img_url($images[0]->path, 600) : img_url(null),
            'image_hover' => $images->get(1) ? img_url($images[1]->path, 600) : null,
            'srcset' => $images->get(0) ? self::srcset($images[0]->path) : null,
            'srcset_hover' => $images->get(1) ? self::srcset($images[1]->path) : null,
            'alt' => $images->get(0)->alt ?? $p->title,
            'price' => $price,
            'compare' => $compare && $compare > $price ? $compare : null,
            'off' => discount_percent($price, $compare),
            'installments' => $inst['n'] > 1 ? $inst : null,
            'single_variant_id' => (int) ($p->variants_count ?? 0) === 1 ? $p->variants()->value('id') : null,
            'rating' => $p->rating_count > 0 ? ['avg' => (float) $p->rating_avg, 'count' => (int) $p->rating_count] : null,
            'sold_out' => $p->track_stock && ! $p->allow_backorder && (int) ($p->stock_total ?? 1) <= 0,
        ];
    }
}
