<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Support\ProductCard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** ForFit: marcas, filtro por marca, ficha técnica, preço no PIX, feed com a marca do fabricante e importador. */
class ForFitStoreTest extends TestCase
{
    use DatabaseTransactions;

    private Brand $growth;

    private Brand $max;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutVite();
        $this->growth = Brand::create(['name' => 'Growth Teste', 'slug' => 'growth-teste-'.uniqid(), 'is_active' => true]);
        $this->max = Brand::create(['name' => 'Max Teste', 'slug' => 'max-teste-'.uniqid(), 'is_active' => true]);
    }

    private function product(Brand $brand, string $title, int $price = 10000, array $extra = []): Product
    {
        $p = Product::create(['title' => $title, 'slug' => \Illuminate\Support\Str::slug($title).'-'.uniqid(), 'status' => 'active', 'brand_id' => $brand->id] + $extra);
        $p->variants()->create(['title' => 'Padrão', 'price' => $price, 'stock' => 10]);

        return $p->fresh();
    }

    public function test_brand_page_lists_only_that_brand(): void
    {
        $this->product($this->growth, 'Creatina Growth Teste');
        $this->product($this->max, 'Creatina Max Teste');

        $this->get('/marcas/'.$this->growth->slug)->assertOk()
            ->assertSee('Creatina Growth Teste')->assertDontSee('Creatina Max Teste');
        $this->get('/marcas/nao-existe')->assertNotFound();
    }

    public function test_inactive_brand_page_is_hidden(): void
    {
        $this->growth->update(['is_active' => false]);
        $this->get('/marcas/'.$this->growth->slug)->assertNotFound();
    }

    public function test_collection_filters_by_brand(): void
    {
        $cat = Category::create(['name' => 'Creatina Teste', 'slug' => 'creatina-teste-'.uniqid(), 'is_active' => true]);
        $a = $this->product($this->growth, 'Creatina Filtro Growth');
        $b = $this->product($this->max, 'Creatina Filtro Max');
        $cat->products()->sync([$a->id => ['position' => 0], $b->id => ['position' => 1]]);

        $this->get('/collections/'.$cat->slug)->assertOk()->assertSee('Creatina Filtro Growth')->assertSee('Creatina Filtro Max')
            ->assertSee('Todas as marcas');
        $this->get('/collections/'.$cat->slug.'?marca='.$this->max->slug)->assertOk()
            ->assertSee('Creatina Filtro Max')->assertDontSee('Creatina Filtro Growth');
    }

    public function test_explicit_sort_overrides_brand_default_order(): void
    {
        $this->product($this->growth, 'Barata Growth', 1000);
        $this->product($this->growth, 'Cara Growth', 90000);

        $this->get('/marcas/'.$this->growth->slug.'?sort_by=price-descending')->assertOk()
            ->assertSeeInOrder(['Cara Growth', 'Barata Growth']);
    }

    public function test_card_shows_brand_and_pix_price(): void
    {
        Setting::set('nixfy.pix_discount_percent', '10');
        $p = $this->product($this->growth, 'Whey Card Teste', 20000);
        $card = ProductCard::make(ProductCard::scope(Product::query())->whereKey($p->id)->first());

        $this->assertSame('Growth Teste', $card['brand']);
        $this->assertSame(18000, $card['pix_price']);
        $this->assertNotNull($card['single_variant_id'], 'produto de 1 variação compra direto do card');
    }

    public function test_product_page_shows_specs_tabs_and_brand(): void
    {
        $p = $this->product($this->max, 'Whey Ficha Teste', 15000, ['specs' => [
            'destaques' => ['25g de proteína por dose'],
            'modo_de_uso' => 'Consumir 1 dose ao dia.',
            'tabela' => ['porcao' => '30 g', 'porcoes' => '30', 'linhas' => [['', '30 g', '%VD*'], ['Proteínas (g)', '24', '48']]],
        ]]);

        $this->get('/products/'.$p->slug)->assertOk()
            ->assertSee('Max Teste')->assertSee('Tabela nutricional')->assertSee('Proteínas (g)')
            ->assertSee('Consumir 1 dose ao dia.')->assertSee('25g de proteína por dose');
    }

    public function test_feed_uses_manufacturer_brand(): void
    {
        $p = $this->product($this->max, 'Feed Marca Teste');
        $p->images()->create(['path' => 'https://exemplo.com/foto.jpg', 'position' => 0]);
        app(\App\Services\Tracking\ProductFeed::class)->build();

        $xml = \Illuminate\Support\Facades\Storage::disk('local')->get(\App\Services\Tracking\ProductFeed::PATH);
        $this->assertStringContainsString('<g:brand>Max Teste</g:brand>', $xml);
    }

    public function test_catalog_importer_creates_brands_flavors_and_collections(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cat').'.json';
        file_put_contents($file, json_encode(['segmentos' => [[
            'key' => 'creatina', 'name' => 'Creatina Importada Teste',
            'growth' => [[
                'rank' => 1, 'origem' => 'mais vendido', 'brand' => 'Growth Supplements', 'title' => 'Creatina Import (TOP)', 'source_url' => 'https://exemplo.com/g1-'.uniqid(),
                'source_id' => '1', 'price' => 5544, 'compare_at' => 6330, 'images' => [], 'weight_grams' => 250,
                'options' => [['name' => 'Sabor', 'values' => ['Natural', 'Limão']]],
                'variants' => [['title' => 'Natural', 'option1' => 'Natural', 'source_sku' => 'a'.uniqid(), 'images' => []], ['title' => 'Limão', 'option1' => 'Limão', 'source_sku' => 'b'.uniqid(), 'images' => []]],
                'caracteristicas' => [], 'info' => ['<b>INGREDIENTES:</b> Creatina.'], 'highlights' => [], 'faq' => [], 'nutrition' => null, 'usage' => null,
            ]],
            'max' => [[
                'rank' => 1, 'origem' => 'kit da marca', 'brand' => 'Max Titanium', 'title' => 'Kit Creatina – Max Titanium', 'source_url' => 'https://exemplo.com/m1-'.uniqid(),
                'source_id' => '2', 'price' => 6990, 'compare_at' => null, 'images' => [], 'weight_grams' => 500, 'options' => [],
                'variants' => [['title' => 'Padrão', 'option1' => null, 'source_sku' => 'c'.uniqid(), 'images' => [], 'price' => 6990]],
                'specs' => ['Sobre o Produto' => '<p>Kit de creatina.</p>'],
            ]],
        ]]]));

        $this->artisan('forfit:catalogo', ['arquivo' => $file, '--stock' => 7])->assertSuccessful();

        $g = Product::where('source_url', 'like', 'https://exemplo.com/g1-%')->latest('id')->first();
        $this->assertSame('Growth Supplements', $g->brand->name);
        $this->assertSame(2, $g->variants()->count());
        $this->assertSame(7, (int) $g->variants()->first()->stock);
        $this->assertSame('Creatina.', $g->specs['ingredientes']);
        $this->assertTrue(Category::where('slug', 'creatina-importada-teste')->first()->products->contains($g));

        $m = Product::where('source_url', 'like', 'https://exemplo.com/m1-%')->latest('id')->first();
        $this->assertSame('Kit Creatina', $m->title, 'nome da marca sai do título');
        $this->assertTrue(Category::where('slug', 'kits')->first()->products->contains($m), 'kit entra na coleção de kits');
    }
}
