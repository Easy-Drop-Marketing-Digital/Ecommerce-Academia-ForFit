<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\ShippingMethod;
use App\Models\Category;
use App\Models\HomeSection;
use App\Models\Product;
use App\Support\ProductCard;
use Illuminate\Support\Facades\Cache;

class HomeController extends Controller
{
    public function __invoke()
    {
        // Seções montadas no admin (Admin → Página inicial). Cache curto para aguentar pico de tráfego.
        $sections = Cache::remember('home.sections', 300, fn () => HomeSection::where('is_active', true)
            ->orderBy('position')->get()
            ->map(fn (HomeSection $s) => ['type' => $s->type, 'title' => $s->title, 'settings' => $s->settings ?? [], 'data' => $this->data($s)])
            ->filter(fn ($s) => ! empty($s['data']))
            ->values()->all());

        return view('store.home', ['sections' => $sections]);
    }

    private function data(HomeSection $s): mixed
    {
        $cfg = $s->settings ?? [];

        return match ($s->type) {
            'slideshow' => Banner::live($cfg['placement'] ?? '')->get()->map(fn (Banner $b) => [
                'desktop' => $b->imageUrl('image_desktop'),
                'mobile' => $b->imageUrl('image_mobile'),
                'link' => $b->link,
                'alt' => $b->alt ?: $b->title ?: setting('store.name'),
            ])->all(),
            'collection_list' => Category::whereIn('id', $cfg['category_ids'] ?? [])->active()->get()
                ->sortBy(fn ($c) => array_search($c->id, $cfg['category_ids']))
                // sem imagem cadastrada na coleção: usa a foto do 1º produto dela
                ->map(fn ($c) => ['name' => $c->name, 'url' => route('category.show', $c->slug), 'image' => img_url($c->image ?: $c->products()->active()
                    ->with(['images' => fn ($i) => $i->orderBy('position')->limit(1)])->first()?->images->first()?->path, 400)])
                ->values()->all(),
            'product_list' => $this->productList($cfg),
            'benefits' => $this->benefits(),
            'brand_list' => Brand::active()->orderBy('position')->withCount(['products' => fn ($q) => $q->active()])->get()
                ->map(fn ($b) => ['name' => $b->name, 'url' => $b->url(), 'logo' => $b->logo ? img_url($b->logo, 400) : null, 'color' => $b->color ?: '#0A4FD6', 'count' => $b->products_count])->all(),
            default => null,
        };
    }

    /** Faixa de benefícios: textos montados a partir das configurações (frete, PIX, parcelas). */
    private function benefits(): array
    {
        $free = ShippingMethod::where('is_active', true)->whereNotNull('free_above')->min('free_above');
        $pix = pix_percent();
        $parcelas = (int) setting('nixfy.interest_free_installments', 1);
        $pickup = ShippingMethod::where('is_active', true)->where('price', 0)->whereNull('free_above')->where('name', 'like', '%retir%')->exists();

        return array_values(array_filter([
            $free ? ['icon' => 'truck', 'title' => 'Frete grátis', 'text' => 'acima de '.money((int) $free)] : null,
            $pix > 0 ? ['icon' => 'pix', 'title' => rtrim(rtrim(number_format($pix, 1, ',', ''), '0'), ',').'% OFF no PIX', 'text' => 'desconto direto no checkout'] : null,
            $parcelas > 1 ? ['icon' => 'card', 'title' => "Até {$parcelas}x sem juros", 'text' => 'no cartão de crédito'] : null,
            $pickup ? ['icon' => 'store', 'title' => 'Retire na academia', 'text' => 'grátis na sua unidade'] : null,
            ['icon' => 'shield', 'title' => 'Produtos originais', 'text' => 'revenda autorizada'],
        ]));
    }

    private function productList(array $cfg): array
    {
        $category = Category::find($cfg['category_id'] ?? 0);
        if (! $category) {
            return [];
        }
        $products = ProductCard::scope(Product::query())
            ->join('category_product', 'category_product.product_id', '=', 'products.id')
            ->where('category_product.category_id', $category->id)
            ->orderBy('category_product.position')
            ->select('products.*')
            ->limit($cfg['limit'] ?? 12)->get();

        return [
            'products' => $products->map(fn ($p) => ProductCard::make($p))->all(),
            'view_all' => ! empty($cfg['view_all_label']) ? ['label' => $cfg['view_all_label'], 'url' => route('category.show', $category->slug)] : null,
        ];
    }
}
