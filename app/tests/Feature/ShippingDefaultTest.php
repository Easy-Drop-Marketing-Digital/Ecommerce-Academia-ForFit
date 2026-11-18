<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShippingMethod;
use App\Services\Cart\CartService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Frete pré-selecionado no carrinho e no checkout: entrega grátis quando houver; retirada nunca vem marcada. */
class ShippingDefaultTest extends TestCase
{
    use DatabaseTransactions;

    private ShippingMethod $standard;

    private ShippingMethod $express;

    private ShippingMethod $pickup;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutVite();
        ShippingMethod::query()->update(['is_active' => false]);
        // expressa e retirada antes da padrão na lista, para provar que a ordem não decide
        $this->express = ShippingMethod::create(['name' => 'Expressa', 'price' => 2990, 'min_days' => 1, 'max_days' => 4, 'position' => 0, 'is_active' => true]);
        $this->pickup = ShippingMethod::create(['name' => 'Retirar na academia', 'price' => 0, 'is_pickup' => true, 'position' => 1, 'is_active' => true]);
        $this->standard = ShippingMethod::create(['name' => 'Entrega padrão', 'price' => 1990, 'free_above' => 19900, 'min_days' => 3, 'max_days' => 10, 'position' => 2, 'is_active' => true]);
    }

    private function cart(int $price): string
    {
        $p = Product::create(['title' => 'Whey Frete', 'slug' => 'whey-frete-'.uniqid(), 'status' => 'active']);
        $v = $p->variants()->create(['title' => 'Padrão', 'price' => $price, 'stock' => 10]);

        return $this->postJson('/cart/add', ['variant_id' => $v->id, 'quantity' => 1])->assertOk()->json('token');
    }

    public function test_above_free_limit_preselects_free_home_delivery(): void
    {
        $token = $this->cart(25000);
        $cart = $this->withCredentials()->withCookies([CartService::COOKIE => $token])->getJson('/cart.json')->assertOk();

        $cart->assertJsonPath('shipping.name', 'Entrega padrão')->assertJsonPath('shipping.price', 0)
            ->assertJsonPath('shipping.price_formatted', 'Grátis')->assertJsonPath('shipping.missing', null)
            ->assertJsonPath('total', 25000);
        $this->assertStringStartsWith('receba entre ', $cart->json('shipping.estimate'));

        $q = $this->withCredentials()->withCookies([CartService::COOKIE => $token])->postJson('/checkout/quote', [])->assertOk();
        $this->assertSame($this->standard->id, $q->json('shipping_method_id'), 'checkout marca a entrega grátis');
        $this->assertSame(0, $q->json('shipping'));
    }

    public function test_below_free_limit_preselects_cheapest_delivery_not_pickup(): void
    {
        $token = $this->cart(15000);
        $cart = $this->withCredentials()->withCookies([CartService::COOKIE => $token])->getJson('/cart.json')->assertOk();

        $cart->assertJsonPath('shipping.name', 'Entrega padrão')->assertJsonPath('shipping.price', 1990)
            ->assertJsonPath('shipping.missing', 4900)->assertJsonPath('total', 16990);

        $q = $this->withCredentials()->withCookies([CartService::COOKIE => $token])->postJson('/checkout/quote', [])->assertOk();
        $this->assertSame($this->standard->id, $q->json('shipping_method_id'), 'retirada grátis não pode vir marcada sozinha');
        $this->assertSame(4900, $q->json('missing_for_free_shipping'));

        // o cliente ainda pode escolher a retirada
        $q2 = $this->withCredentials()->withCookies([CartService::COOKIE => $token])->postJson('/checkout/quote', ['shipping_method_id' => $this->pickup->id])->assertOk();
        $this->assertSame($this->pickup->id, $q2->json('shipping_method_id'));
        $this->assertSame(0, $q2->json('shipping'));
    }

    public function test_estimate_counts_dispatch_and_business_days_only(): void
    {
        \App\Models\Setting::set('delivery.processing_days', 'até 1 dia útil');
        // quarta 18/11/2026 + 1 dia de despacho + 3 a 10 dias úteis (pula fins de semana)
        $e = $this->standard->estimate(CarbonImmutable::create(2026, 11, 18, 10, 0, 0, 'America/Sao_Paulo'));

        $this->assertSame('2026-11-24', $e['from']);
        $this->assertSame('2026-12-03', $e['to']);
        $this->assertSame('receba entre 24 de nov. e 3 de dez.', $e['label']);
        $this->assertNull($this->pickup->estimate(), 'retirada não tem previsão de entrega');
    }
}
