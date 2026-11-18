<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Redirect;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Links da loja antiga (Shopify) e de anúncios continuam funcionando. */
class LegacyLinksTest extends TestCase
{
    use DatabaseTransactions;

    private Product $product;

    private ProductVariant $p;

    private ProductVariant $m;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutVite();
        $this->product = Product::create(['title' => 'Vestido Link', 'slug' => 'vestido-link-'.uniqid(), 'status' => 'active']);
        $this->p = $this->product->variants()->create(['title' => 'P', 'option1' => 'P', 'price' => 10000, 'stock' => 10, 'shopify_id' => 47000000000001]);
        $this->m = $this->product->variants()->create(['title' => 'M', 'option1' => 'M', 'price' => 10000, 'stock' => 10, 'shopify_id' => 47000000000002]);
    }

    public function test_variant_param_accepts_shopify_ids(): void
    {
        $this->get('/products/'.$this->product->slug.'?variant=47000000000002')
            ->assertOk()->assertViewHas('selected', fn ($s) => $s['id'] === $this->m->id);
        $this->get('/products/'.$this->product->slug.'?variant='.$this->p->id)
            ->assertOk()->assertViewHas('selected', fn ($s) => $s['id'] === $this->p->id);
    }

    public function test_cart_permalink_fills_cart_and_goes_to_checkout_with_coupon(): void
    {
        Discount::create(['title' => 'Cupom link', 'code' => 'LINK10', 'type' => 'percentage', 'value' => 10, 'is_active' => true]);

        $res = $this->get('/cart/47000000000001:2,'.$this->m->id.':1?discount=link10');
        $res->assertRedirect(route('checkout'));

        $cart = Cart::with('items')->latest('id')->first();
        $this->assertSame(3, (int) $cart->items->sum('quantity'));
        $this->assertSame('LINK10', $cart->discount_code);

        // No checkout o cupom já vem aplicado
        $this->withCookies([CartService::COOKIE => $cart->token])->get('/checkout')
            ->assertOk()->assertViewHas('coupon', 'LINK10');
    }

    public function test_discount_link_only_redirects_inside_the_store(): void
    {
        $this->get('/discount/FORFIT10?redirect=/collections/vestidos')->assertRedirect('/collections/vestidos')->assertSessionHas('discount_code', 'FORFIT10');
        $this->get('/discount/FORFIT10?redirect=//site-malicioso.com')->assertRedirect('/');
        $this->get('/discount/FORFIT10?redirect=https://site-malicioso.com')->assertRedirect('/');
    }

    public function test_renamed_product_keeps_old_link_working(): void
    {
        $old = $this->product->slug;
        $this->product->update(['slug' => $old.'-novo']);

        $this->get('/products/'.$old.'?utm_source=facebook')
            ->assertStatus(301)->assertRedirect('/products/'.$old.'-novo?utm_source=facebook');

        // renomear de novo não cria corrente de redirecionamentos
        $this->product->update(['slug' => $old.'-v3']);
        $this->assertSame('/products/'.$old.'-v3', Redirect::where('from_path', '/products/'.$old)->value('to_url'));
    }

    public function test_manual_redirect_for_removed_page(): void
    {
        Redirect::create(['from_path' => '/Collections/Black-Friday-2025/', 'to_url' => '/collections/all']);

        $this->get('/collections/black-friday-2025')->assertStatus(301)->assertRedirect('/collections/all');
        $this->assertSame(1, Redirect::where('from_path', '/collections/black-friday-2025')->value('hits'));
        $this->get('/pagina-que-nao-existe')->assertNotFound();
    }
}
