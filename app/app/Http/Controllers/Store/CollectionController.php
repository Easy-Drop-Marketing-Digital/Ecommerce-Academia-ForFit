<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\ProductCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    public const SORTS = [
        'manual' => 'Em destaque',
        'best-selling' => 'Mais vendidos',
        'title-ascending' => 'Ordem alfabética, A–Z',
        'title-descending' => 'Ordem alfabética, Z–A',
        'price-ascending' => 'Preço, ordem crescente',
        'price-descending' => 'Preço, ordem decrescente',
        'created-descending' => 'Data, mais recentes primeiro',
        'created-ascending' => 'Data, mais antigos primeiro',
    ];

    public function show(Request $request, string $slug)
    {
        if ($slug === 'all') {
            $category = new Category(['name' => 'Todos os produtos', 'slug' => 'all']);
            $query = Product::query();
        } else {
            $category = Category::active()->where('slug', $slug)->firstOrFail();
            $query = Product::query()
                ->join('category_product', 'category_product.product_id', '=', 'products.id')
                ->where('category_product.category_id', $category->id)
                ->select('products.*', 'category_product.position as cat_position');
        }

        return $this->listing($request, $query, $category, [
            'title' => $category->name,
            'description' => $category->description,
            'seo_title' => ($category->seo_title ?: $category->name).' | '.setting('store.name'),
            'seo_description' => $category->seo_description ?: strip_tags((string) $category->description),
            'canonical' => route('category.show', $category->slug),
        ]);
    }

    /** Página da marca: /marcas/{slug} — mesma listagem das coleções, só com os produtos da marca. */
    public function brand(Request $request, string $slug)
    {
        $brand = Brand::active()->where('slug', $slug)->firstOrFail();
        $category = new Category(['name' => $brand->name, 'slug' => $brand->slug, 'sort_order' => 'manual']);
        // "Em destaque": melhor posição do produto nas coleções (ranking de mais vendidos das marcas), depois vendas reais
        $query = Product::query()->where('products.brand_id', $brand->id)
            ->orderByRaw('(select min(cp.position) from category_product cp where cp.product_id = products.id) is null, (select min(cp.position) from category_product cp where cp.product_id = products.id)');

        return $this->listing($request, $query, $category, [
            'title' => $brand->name,
            'description' => $brand->description,
            'seo_title' => ($brand->seo_title ?: $brand->name).' | '.setting('store.name'),
            'seo_description' => $brand->seo_description ?: strip_tags((string) $brand->description),
            'canonical' => route('brand.show', $brand->slug),
            'brand' => $brand,
        ]);
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $query = Product::query();
        if ($q !== '') {
            $terms = array_filter(preg_split('/\s+/', mb_substr($q, 0, 80)));
            foreach ($terms as $t) {
                $query->where(fn (Builder $w) => $w->where('title', 'like', "%$t%")->orWhere('tags', 'like', "%$t%"));
            }
        } else {
            $query->whereRaw('1 = 0');
        }

        return $this->listing($request, $query, null, [
            'title' => $q !== '' ? 'Resultados para “'.$q.'”' : 'Buscar',
            'description' => null,
            'seo_title' => 'Busca | '.setting('store.name'),
            'seo_description' => null,
            'canonical' => null,
            'search' => $q,
            'noindex' => true,
        ]);
    }

    private function listing(Request $request, Builder $query, ?Category $category, array $meta)
    {
        // Ordem escolhida pela cliente na tela; senão, a ordem padrão definida na coleção (Admin → Coleções)
        $default = match ($category?->sort_order) {
            'best_selling' => 'best-selling',
            'price_asc' => 'price-ascending',
            'price_desc' => 'price-descending',
            'newest' => 'created-descending',
            default => 'manual',
        };
        $sort = array_key_exists($request->query('sort_by'), self::SORTS) ? $request->query('sort_by') : $default;
        $availability = $request->query('availability'); // in|out
        $min = $request->filled('price_min') ? (int) round((float) str_replace(',', '.', $request->query('price_min')) * 100) : null;
        $max = $request->filled('price_max') ? (int) round((float) str_replace(',', '.', $request->query('price_max')) * 100) : null;

        $query = ProductCard::scope($query);

        // Marcas presentes nesta listagem (filtro "Marca") e filtro escolhido
        $brands = Brand::active()->whereIn('id', (clone $query)->reorder()->whereNotNull('products.brand_id')->select('products.brand_id'))
            ->orderBy('position')->get(['id', 'name', 'slug']);
        $brandSlug = (string) $request->query('marca', '');
        if ($brandSlug !== '' && ($b = $brands->firstWhere('slug', $brandSlug))) {
            $query->where('products.brand_id', $b->id);
        }

        // Faixa de preço disponível (antes dos filtros) para o filtro "Preço"
        $priceCeiling = (int) (clone $query)->reorder()->max('products.min_price');

        if ($min !== null) {
            $query->where('products.min_price', '>=', $min);
        }
        if ($max !== null) {
            $query->where('products.min_price', '<=', $max);
        }
        if (in_array($availability, ['in', 'out'], true)) {
            $stock = fn ($v) => $v->where('is_active', true)->where('stock', '>', 0);
            $availability === 'in' ? $query->whereHas('variants', $stock) : $query->whereDoesntHave('variants', $stock);
        }

        if ($sort !== 'manual') {
            $query->reorder(); // ordem escolhida pelo cliente substitui a ordem padrão da página
        }
        match ($sort) {
            'best-selling' => $query->orderByDesc('products.sales_count'),
            'title-ascending' => $query->orderBy('products.title'),
            'title-descending' => $query->orderByDesc('products.title'),
            'price-ascending' => $query->orderBy('products.min_price'),
            'price-descending' => $query->orderByDesc('products.min_price'),
            'created-descending' => $query->orderByDesc('products.id'),
            'created-ascending' => $query->orderBy('products.id'),
            default => $category?->exists ? $query->orderBy('cat_position') : $query->orderByDesc('products.sales_count')->orderBy('products.id'),
        };

        $paginator = $query->paginate(24)->withQueryString();

        return view('store.collection', [
            'category' => $category,
            'meta' => $meta,
            'cards' => collect($paginator->items())->map(fn ($p) => ProductCard::make($p)),
            'paginator' => $paginator,
            'sort' => $sort,
            'sorts' => self::SORTS,
            'brands' => $brands,
            'filters' => ['brand' => $brandSlug, 'availability' => $availability, 'price_min' => $min, 'price_max' => $max, 'price_ceiling' => $priceCeiling],
        ]);
    }
}
