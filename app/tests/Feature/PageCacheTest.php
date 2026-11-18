<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\PageCache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PageCacheTest extends TestCase
{
    use DatabaseTransactions;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutVite();
        config(['store.page_cache' => true, 'services.meta.pixel_id' => '123456789012345']);
        PageCache::flush();
        $this->product = Product::create(['title' => 'Vestido Cache', 'slug' => 'vestido-cache-'.uniqid(), 'status' => 'active']);
        $this->variant = $this->product->variants()->create(['title' => 'M', 'price' => 12990, 'stock' => 10]);
    }

    protected function tearDown(): void
    {
        PageCache::flush();
        parent::tearDown();
    }

    private function url(string $query = ''): string
    {
        return '/products/'.$this->product->slug.$query;
    }

    /** Visitante novo (sessão e cookies próprios) */
    private function visitor(): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->withUnencryptedCookies(['sd_vid' => 'v'.uniqid()]);
    }

    private static function between(string $html, string $hole): string
    {
        preg_match("#<!--sd:$hole-->(.*?)<!--/sd:$hole-->#s", $html, $m);

        return $m[1] ?? '';
    }

    public function test_second_visit_is_served_from_cache_with_its_own_token_cart_and_pixel_data(): void
    {
        $first = $this->visitor()->get($this->url())->assertOk()->assertHeader('X-Page-Cache', 'MISS');
        $tokenA = csrf_token();
        $trackA = self::between($first->getContent(), 'track');

        // Visitante B, com carrinho
        $this->visitor();
        $token = $this->postJson('/cart/add', ['variant_id' => $this->variant->id, 'quantity' => 2])->assertOk()->json('token');
        $second = $this->withCookies([\App\Services\Cart\CartService::COOKIE => $token])->get($this->url())->assertOk()->assertHeader('X-Page-Cache', 'HIT');
        $html = $second->getContent();

        $this->assertStringContainsString('Vestido Cache', $html);
        $this->assertStringNotContainsString($tokenA, $html, 'token CSRF de outra visitante vazou');
        $this->assertStringContainsString('content="'.csrf_token().'"', $html);
        $this->assertStringContainsString('"count":2', self::between($html, 'cart'));
        $this->assertNotSame($trackA, self::between($html, 'track'), 'dados do pixel de outra visitante vazaram');
        $this->assertStringNotContainsString('%%SD_CSRF%%', $html);
    }

    public function test_campaign_params_share_the_cache_and_unknown_params_skip_it(): void
    {
        $this->get($this->url())->assertHeader('X-Page-Cache', 'MISS');
        $this->get($this->url('?utm_source=facebook&fbclid=abc123'))->assertHeader('X-Page-Cache', 'HIT');
        $this->get($this->url('?qualquer=coisa'))->assertOk()->assertHeaderMissing('X-Page-Cache');
    }

    public function test_admin_change_and_sold_out_clear_the_cache_but_a_regular_sale_does_not(): void
    {
        $this->get($this->url())->assertHeader('X-Page-Cache', 'MISS');

        $this->variant->adjustStock(-1, 'sale'); // 10 → 9
        $this->get($this->url())->assertHeader('X-Page-Cache', 'HIT');

        $this->product->update(['title' => 'Vestido Cache Novo']);
        $this->get($this->url())->assertHeader('X-Page-Cache', 'MISS')->assertSee('Vestido Cache Novo');

        $this->variant->fresh()->adjustStock(-9, 'sale'); // 9 → 0: esgotou
        $this->get($this->url())->assertHeader('X-Page-Cache', 'MISS');
    }

    public function test_logged_in_customer_is_never_cached(): void
    {
        $this->get($this->url())->assertHeader('X-Page-Cache', 'MISS');
        $customer = Customer::create(['name' => 'Rita', 'email' => 'rita-'.uniqid().'@example.com', 'password' => 'senha-segura-123']);

        $this->actingAs($customer, 'customer')->get($this->url())->assertOk()->assertHeaderMissing('X-Page-Cache');
    }

    public function test_cache_is_off_unless_enabled(): void
    {
        config(['store.page_cache' => false]);
        $this->get($this->url())->assertOk()->assertHeaderMissing('X-Page-Cache');
    }
}
