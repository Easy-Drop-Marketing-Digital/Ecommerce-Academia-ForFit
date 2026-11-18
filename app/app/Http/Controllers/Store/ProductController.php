<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\ProductCard;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function show(Request $request, string $slug)
    {
        $product = Product::active()->where('slug', $slug)
            ->with(['brand', 'images', 'options', 'variants' => fn ($v) => $v->where('is_active', true), 'categories'])
            ->firstOrFail();

        $images = $product->images->values();
        $imageIndex = $images->pluck('id')->flip();

        $variants = $product->variants->each(fn ($v) => $v->setRelation('product', $product))->map(fn ($v) => [
            'id' => $v->id,
            'content_id' => \App\Services\Tracking\ContentId::for($v),
            'title' => $v->title,
            'options' => array_values(array_filter([$v->option1, $v->option2, $v->option3], fn ($o) => $o !== null)),
            'price' => $v->price,
            'compare' => $v->compare_at_price,
            'available' => $v->inStock(),
            'image' => $v->image_id !== null ? ($imageIndex[$v->image_id] ?? null) : null,
            'sku' => $v->sku,
        ])->values();

        // ?variant= aceita o id da loja nova e o da Shopify (links antigos do Google Shopping e dos anúncios)
        $wanted = (int) $request->query('variant');
        $shopifyMatch = $wanted ? $product->variants->firstWhere('shopify_id', $wanted) : null;
        $selected = $variants->firstWhere('id', $shopifyMatch?->id ?? $wanted)
            ?? $variants->firstWhere('available', true)
            ?? $variants->first();

        $related = collect();
        if ($cat = $product->categories->first()) {
            $related = ProductCard::scope(Product::query())
                ->whereHas('categories', fn ($q) => $q->where('categories.id', $cat->id))
                ->where('products.id', '!=', $product->id)
                ->orderByDesc('sales_count')->limit(10)->get()
                ->map(fn ($p) => ProductCard::make($p));
        }

        // Avaliações: resumo (média + distribuição) e primeira página já no HTML (SEO e carregamento rápido)
        $dist = \App\Models\Review::approved()->where('product_id', $product->id)
            ->selectRaw('rating, COUNT(*) as n')->groupBy('rating')->pluck('n', 'rating');
        $firstReviews = \App\Models\Review::approved()->where('product_id', $product->id)
            ->orderByDesc('featured')->orderByDesc('created_at')->limit(8)->get();
        $reviews = [
            'avg' => (float) $product->rating_avg,
            'count' => (int) $product->rating_count,
            'dist' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($s) => [$s => (int) ($dist[$s] ?? 0)])->all(),
            'items' => $firstReviews->map(fn ($r) => ReviewController::present($r))->all(),
            'next' => $product->rating_count > 8 ? 2 : null,
            'url' => route('reviews.index', $product->slug),
        ];

        return view('store.product', [
            'reviews' => $reviews,
            'product' => $product,
            'images' => $images->map(fn ($i) => [
                'src' => img_url($i->path, 1200),
                // celular baixa a de 600/900px; desktop/retina a de 1200px
                'srcset' => ProductCard::srcset($i->path, [600, 900, 1200]),
                'thumb' => img_url($i->path, 200),
                'alt' => $i->alt ?: $product->title,
            ])->all(),
            'options' => $product->options->map(fn ($o) => ['name' => $o->name, 'type' => $o->display_type, 'values' => $o->values])->values()->all(),
            'variants' => $variants->all(),
            'selected' => $selected,
            'plan' => installment_plan($selected['price'] ?? $product->min_price),
            'related' => $related,
            'category' => $product->categories->first(),
        ]);
    }
}
