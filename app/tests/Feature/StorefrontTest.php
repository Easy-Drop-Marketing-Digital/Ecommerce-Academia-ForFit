<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use DatabaseTransactions;

    private Product $product;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutVite();

        $this->category = Category::create(['name' => 'Vestidos Teste', 'slug' => 'vestidos-teste-'.uniqid()]);
        $this->product = Product::create(['title' => 'Vestido Teste Rendado', 'slug' => 'vestido-teste-'.uniqid(), 'status' => 'active', 'description' => '<p>Lindo vestido</p>']);
        $this->product->options()->create(['name' => 'Tamanho', 'position' => 1, 'values' => ['P', 'M']]);
        $this->product->variants()->create(['title' => 'P', 'option1' => 'P', 'price' => 12990, 'compare_at_price' => 19990, 'stock' => 5, 'position' => 1]);
        $this->product->variants()->create(['title' => 'M', 'option1' => 'M', 'price' => 12990, 'compare_at_price' => 19990, 'stock' => 0, 'position' => 2]);
        $this->product->refreshPriceCache();
        $this->category->products()->attach($this->product->id, ['position' => 0]);
    }

    public function test_product_page_renders_price_installments_and_structured_data(): void
    {
        $res = $this->get('/products/'.$this->product->slug);

        $res->assertOk()
            ->assertSee('Vestido Teste Rendado')
            ->assertSee('R$ 129,90')
            ->assertSee('-35%')
            ->assertSee('no PIX')
            ->assertSee('sem juros')
            ->assertSee('"@type":"Product"', false)
            ->assertSee('https://schema.org/OutOfStock', false); // variação M sem estoque
    }

    public function test_collection_lists_product_and_filters_by_price(): void
    {
        $url = '/collections/'.$this->category->slug;

        $this->get($url)->assertOk()->assertSee('Vestido Teste Rendado');
        $this->get($url.'?price_max=100')->assertOk()->assertDontSee('Vestido Teste Rendado');
        $this->get($url.'?availability=in')->assertOk()->assertSee('Vestido Teste Rendado');
    }

    public function test_search_finds_by_title(): void
    {
        $this->get('/search?q=rendado')->assertOk()->assertSee('Vestido Teste Rendado');
        $this->get('/search?q=inexistente-xyz')->assertOk()->assertSee('Nenhum produto encontrado');
    }

    public function test_cart_add_update_remove_and_stock_limit(): void
    {
        $p = $this->product->variants()->where('option1', 'P')->first();
        $m = $this->product->variants()->where('option1', 'M')->first();

        $res = $this->postJson('/cart/add', ['variant_id' => $p->id, 'quantity' => 2]);
        $res->assertOk()->assertJsonPath('count', 2)->assertJsonPath('subtotal', 25980)->assertJsonPath('savings', 14000);
        $token = $res->json('token');
        $itemId = $res->json('items.0.id');

        $cookie = [CartService::COOKIE => $token];

        // estoque da P é 5: pedir 6 falha com mensagem
        $this->withCredentials()->withCookies($cookie)->postJson('/cart/update', ['item_id' => $itemId, 'quantity' => 6])
            ->assertStatus(422)->assertJsonPath('error', 'Quantidade indisponível em estoque para esta opção.');

        // variação M está esgotada
        $this->withCredentials()->withCookies($cookie)->postJson('/cart/add', ['variant_id' => $m->id])->assertStatus(422);

        $this->withCredentials()->withCookies($cookie)->postJson('/cart/update', ['item_id' => $itemId, 'quantity' => 3])
            ->assertOk()->assertJsonPath('count', 3);
        $this->withCredentials()->withCookies($cookie)->postJson('/cart/update', ['item_id' => $itemId, 'quantity' => 0])
            ->assertOk()->assertJsonPath('count', 0);
    }

    public function test_policies_keep_shopify_urls(): void
    {
        $this->seed(\Database\Seeders\StoreContentSeeder::class);

        $this->get('/policies/refund-policy')->assertOk()->assertSee('Direito de arrependimento');
        $this->get('/pages/refund-policy')->assertRedirect('/policies/refund-policy');
        $this->get('/sitemap.xml')->assertOk()->assertSee('/products/'.$this->product->slug, false);
    }
}
