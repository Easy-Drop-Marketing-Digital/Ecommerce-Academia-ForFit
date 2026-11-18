<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use DatabaseTransactions;

    private ProductVariant $variant;

    private ?string $cartToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutVite();
        config(['services.nixfy.public_key' => 'pk_test', 'services.nixfy.private_key' => 'sk_test', 'services.nixfy.base_url' => 'https://api.nixfy.test/api']);

        // Isola o teste dos dados importados
        Discount::query()->update(['is_active' => false]);
        ShippingMethod::query()->update(['is_active' => false]);
        DB::table('settings')->where('key', 'like', 'nixfy.%')->delete();
        DB::table('card_attempts')->delete();
        // contas do gateway conferidas sem desconto no PIX e com juros a partir da 2ª parcela (padrão da ForFit é 10% e 6x)
        Setting::set('nixfy.pix_discount_percent', '0');
        Setting::set('nixfy.interest_free_installments', '1');
        ShippingMethod::create(['name' => 'Frete Grátis', 'price' => 0, 'min_days' => 10, 'max_days' => 25, 'is_active' => true, 'position' => 0]);

        $p = Product::create(['title' => 'Conjunto Teste', 'slug' => 'conjunto-teste-'.uniqid(), 'status' => 'active']);
        $p->options()->create(['name' => 'Tamanho', 'position' => 1, 'values' => ['M']]);
        $this->variant = $p->variants()->create(['title' => 'M', 'option1' => 'M', 'price' => 10000, 'compare_at_price' => 20000, 'stock' => 10]);
        $p->refreshPriceCache();
    }

    private function addToCart(int $qty = 1, ?ProductVariant $v = null): void
    {
        $res = $this->postJson('/cart/add', ['variant_id' => ($v ?? $this->variant)->id, 'quantity' => $qty])->assertOk();
        $this->cartToken = $res->json('token');
    }

    private function cartJson(string $method, string $uri, array $data = [])
    {
        return $this->withCredentials()->withCookies([CartService::COOKIE => $this->cartToken])->json($method, $uri, $data);
    }

    private function customer(array $extra = []): array
    {
        return array_merge([
            'email' => 'Maria.Teste@Example.com', 'name' => 'Maria Teste', 'phone' => '(11) 98765-4321', 'document' => '529.982.247-25',
            'zip' => '01001-000', 'street' => 'Praça da Sé', 'number' => '100', 'neighborhood' => 'Sé', 'city' => 'São Paulo', 'state' => 'SP',
        ], $extra);
    }

    private function fakeNixfy(string $status = 'PENDENTE', string $method = 'PIX', string $txStatus = 'PAGO'): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory); // zera respostas simuladas anteriores
        Http::fake([
            'api.nixfy.test/api/sales' => Http::response(['message' => 'ok', 'sale' => [
                'id' => 1, 'uuid' => 'uuid-'.uniqid(), 'amount' => '0', 'status' => $status, 'paymentMethod' => $method,
                'payment' => $method === 'PIX'
                    ? ['method' => 'PIX', 'pix' => ['key' => '00020101PIXCOPIAECOLA', 'qrCodeBase64' => 'data:image/png;base64,AAAA', 'expiresAt' => null]]
                    : ['method' => 'CREDIT_CARD', 'card' => ['lastDigits' => '1111', 'brand' => 'VISA']],
            ]], 201),
            'api.nixfy.test/api/transactions/*' => Http::response(['status' => $txStatus, 'systemFee' => 300]),
        ]);
    }

    public function test_pix_checkout_creates_order_reserves_stock_and_sends_matching_amount(): void
    {
        // como em produção: o valor salvo no admin chega à config como texto
        config(['services.nixfy.pix_expires_in_days' => '1']);
        $this->fakeNixfy();
        $this->addToCart(2);

        $res = $this->cartJson('POST', '/checkout', $this->customer(['payment_method' => 'pix']));
        $res->assertOk()->assertJsonStructure(['redirect']);

        $order = Order::latest('id')->first();
        $this->assertSame('pending', $order->status);
        $this->assertSame(20000, $order->total);
        $this->assertSame('maria.teste@example.com', $order->customer_email);
        $this->assertSame('52998224725', $order->customer_document);
        $this->assertSame('00020101PIXCOPIAECOLA', $order->pix_code);
        $this->assertSame(8, $this->variant->fresh()->stock, 'estoque reservado');
        $this->assertSame(['Tamanho' => 'M'], $order->items->first()->properties);

        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/sales')) {
                return false;
            }
            $sum = array_sum(array_map(fn ($i) => $i['unitPrice'] * $i['quantity'], $r['items']));

            return $r['amount'] === 20000 && $sum === 20000 && $r['paymentMethod'] === 'PIX'
                && $r['customer']['document']['number'] === '52998224725' && $r->hasHeader('X-Api-Private-Key', 'sk_test');
        });

        // A validade do PIX vem do admin (gravada como texto) e a Nixfy exige número: "1" recusado com HTTP 400
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sales') && $r['pix']['expiresInDays'] === 1);

        // Página do pedido mostra o PIX; a consulta de status confirma o pagamento
        $this->get($order->publicUrl())->assertOk()->assertSee('00020101PIXCOPIAECOLA');
        $this->getJson(route('order.status.json', $order->public_token))->assertJsonPath('paid', true);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(2, (int) $order->fresh()->items->first()->product->sales_count);
    }

    public function test_coupon_pix_discount_and_items_still_match_amount(): void
    {
        $this->fakeNixfy();
        Setting::set('nixfy.pix_discount_percent', '5');
        Discount::create(['title' => 'Boas-vindas', 'code' => 'FORFIT10', 'type' => 'percentage', 'value' => 10, 'is_active' => true, 'min_subtotal' => 15000]);
        $this->addToCart(2);

        $q = $this->cartJson('POST', '/checkout/quote', ['code' => 'forfit10', 'payment_method' => 'pix'])->assertOk();
        $q->assertJsonPath('code_status', 'applied')->assertJsonPath('discount_total', 2900)->assertJsonPath('total', 17100);

        $this->cartJson('POST', '/checkout', $this->customer(['payment_method' => 'pix', 'coupon' => 'FORFIT10']))->assertOk();
        $order = Order::latest('id')->first();
        $this->assertSame(17100, $order->total);
        $this->assertSame('FORFIT10', $order->discount_codes);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sales')
            && $r['amount'] === 17100
            && array_sum(array_map(fn ($i) => $i['unitPrice'] * $i['quantity'], $r['items'])) === 17100);
    }

    public function test_coupon_minimum_not_met_returns_message(): void
    {
        Discount::create(['title' => 'X', 'code' => 'MIN300', 'type' => 'percentage', 'value' => 10, 'is_active' => true, 'min_subtotal' => 30000]);
        $this->addToCart(1);

        $this->cartJson('POST', '/checkout/quote', ['code' => 'MIN300'])
            ->assertJsonPath('code_status', 'not_eligible')
            ->assertJsonPath('discount_total', 0);
    }

    public function test_card_installments_with_interest_and_refused_then_retry_with_pix(): void
    {
        $this->fakeNixfy('RECUSADO', 'CREDIT_CARD');
        $this->addToCart(1);

        $res = $this->cartJson('POST', '/checkout', $this->customer([
            'payment_method' => 'credit_card', 'installments' => 3,
            'card_number' => '4111 1111 1111 1111', 'card_holder' => 'MARIA TESTE', 'card_exp_month' => 12, 'card_exp_year' => 2031, 'card_cvv' => '123',
        ]));
        $res->assertStatus(402)->assertJsonStructure(['error', 'redirect']);

        $order = Order::latest('id')->first();
        $this->assertSame('refused', $order->payment_status);
        $this->assertGreaterThan(10000, $order->total, 'parcelado em 3x com juros');
        $this->assertSame('1111', $order->card_last4);
        $this->assertNotNull($order->card_fingerprint);
        // Número completo e CVV não ficam em lugar nenhum
        $this->assertStringNotContainsString('4111111111111111', json_encode($order->transactions()->get()));

        // Nova tentativa com PIX → recalcula sem juros
        $this->fakeNixfy('PENDENTE', 'PIX');
        $this->postJson(route('order.retry', $order->public_token), ['payment_method' => 'pix'])->assertOk();
        $order->refresh();
        $this->assertSame('pix', $order->payment_method);
        $this->assertSame(10000, $order->total);
        $this->assertSame('pending', $order->payment_status);
        $this->assertNotNull($order->pix_code);
    }

    public function test_invalid_cpf_and_expired_card_are_rejected(): void
    {
        $this->addToCart(1);
        $this->cartJson('POST', '/checkout', $this->customer(['document' => '111.111.111-11', 'payment_method' => 'pix']))
            ->assertStatus(422)->assertJsonValidationErrors(['document']);

        $this->cartJson('POST', '/checkout', $this->customer([
            'payment_method' => 'credit_card', 'card_number' => '4111111111111111', 'card_holder' => 'X', 'card_exp_month' => 1, 'card_exp_year' => 2020, 'card_cvv' => '123',
        ]))->assertStatus(422)->assertJsonValidationErrors(['card_exp_month']);
        $this->assertSame(0, Order::where('customer_document', '11111111111')->count());
    }

    public function test_out_of_stock_at_checkout_rolls_back_everything(): void
    {
        $this->fakeNixfy();
        $this->addToCart(3);
        $this->variant->update(['stock' => 2]); // alguém comprou antes

        $this->cartJson('POST', '/checkout', $this->customer(['payment_method' => 'pix']))
            ->assertStatus(422)->assertJsonPath('error', fn ($m) => str_contains($m, 'esgotou'));

        $this->assertSame(2, $this->variant->fresh()->stock);
        $this->assertSame(0, Order::where('customer_email', 'maria.teste@example.com')->count());
        Http::assertNothingSent();
    }

    public function test_expired_pix_is_canceled_and_stock_released(): void
    {
        $this->fakeNixfy();
        $this->addToCart(2);
        $this->cartJson('POST', '/checkout', $this->customer(['payment_method' => 'pix']))->assertOk();
        $order = Order::latest('id')->first();
        $order->update(['pix_expires_at' => now()->subHours(2)]);

        $this->fakeNixfy('PENDENTE', 'PIX', 'PENDENTE');
        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame('canceled', $order->fresh()->status);
        $this->assertSame(10, $this->variant->fresh()->stock, 'estoque devolvido');

        // Rodar de novo não devolve em dobro
        $this->artisan('orders:expire')->assertSuccessful();
        $order->fresh()->releaseStock();
        $this->assertSame(10, $this->variant->fresh()->stock);
    }

    public function test_track_order_by_number_and_email(): void
    {
        $this->fakeNixfy();
        $this->addToCart(1);
        $this->cartJson('POST', '/checkout', $this->customer(['payment_method' => 'pix']))->assertOk();
        $order = Order::latest('id')->first();

        $this->post('/rastrear', ['number' => '#'.$order->number, 'email' => 'MARIA.TESTE@example.com'])->assertRedirect($order->publicUrl());
        $this->post('/rastrear', ['number' => $order->number, 'email' => 'outra@example.com'])->assertSessionHasErrors('number');
    }
}
