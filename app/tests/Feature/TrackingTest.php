<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\TrackingDispatch;
use App\Models\VisitorSession;
use App\Services\Cart\CartService;
use App\Services\Tracking\ContentId;
use App\Services\Tracking\Platforms\Utmify;
use App\Services\Tracking\TrackingService;
use App\Services\Tracking\UserData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrackingTest extends TestCase
{
    use DatabaseTransactions;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutVite();
        DB::table('settings')->where('key', 'like', 'catalog.%')->delete();
        config([
            'services.meta.pixel_id' => '1234567890', 'services.meta.capi_token' => 'EAATEST',
            'services.tiktok.pixel_id' => 'TTPIXEL', 'services.tiktok.access_token' => 'TTTOKEN',
            'services.google.ga4_id' => null, 'services.utmify.api_token' => null,
        ]);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'x']),
            'business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK']),
        ]);
        $p = Product::create(['title' => 'Vestido Rastreio', 'slug' => 'vestido-rastreio-'.uniqid(), 'status' => 'active', 'shopify_id' => 111]);
        $this->variant = $p->variants()->create(['title' => 'P', 'price' => 12990, 'stock' => 5, 'shopify_id' => 222, 'sku' => 'SKU-P']);
    }

    public function test_user_data_is_normalized_and_hashed_like_meta_requires(): void
    {
        $u = UserData::make()->set([
            'email' => '  Maria.Silva@Example.COM ', 'phone' => '(11) 98765-4321', 'first_name' => 'Mária', 'last_name' => "D'Ávila",
            'city' => 'São Paulo', 'state' => 'SP', 'zip' => '01001-000', 'external_id' => 'ABC-uuid',
        ]);
        $m = $u->forMeta();

        $this->assertSame([hash('sha256', 'maria.silva@example.com')], $m['em']);
        $this->assertSame([hash('sha256', '5511987654321')], $m['ph']);
        $this->assertSame([hash('sha256', 'maria')], $m['fn']);
        $this->assertSame([hash('sha256', 'davila')], $m['ln']);
        $this->assertSame([hash('sha256', 'saopaulo')], $m['ct']);
        $this->assertSame([hash('sha256', 'sp')], $m['st']);
        $this->assertSame([hash('sha256', '01001000')], $m['zp']);
        $this->assertSame([hash('sha256', 'br')], $m['country']);
        $this->assertSame([hash('sha256', 'abc-uuid')], $m['external_id']);
    }

    public function test_content_id_formats(): void
    {
        $this->assertSame((string) $this->variant->id, ContentId::for($this->variant));

        // formato antigo "shopify" (removido) cai no ID desta loja: nunca expõe IDs de outra plataforma
        Setting::set('catalog.content_id_format', 'shopify');
        ContentId::reset();
        $this->assertSame((string) $this->variant->id, ContentId::for($this->variant->fresh('product')));

        Setting::set('catalog.content_id_format', 'sku');
        ContentId::reset();
        $this->assertSame('SKU-P', ContentId::for($this->variant));
    }

    public function test_beacon_records_analytics_and_queues_capi_with_server_side_price(): void
    {
        // Carrinho com e-mail digitado no checkout → vai em hash no user_data
        $token = $this->postJson('/cart/add', ['variant_id' => $this->variant->id])->json('token');
        \App\Models\Cart::where('token', $token)->update(['email' => 'cliente@example.com']);

        $this->withCredentials()->withCookies([CartService::COOKIE => $token])
            ->withUnencryptedCookies(['_fbc' => 'fb.1.1700000000000.ABC', '_fbp' => 'fb.1.1700000000000.999'])
            ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile')
            ->postJson('/t', [
                'name' => 'add_to_cart', 'event_id' => 'evt-12345678', 'url' => 'http://localhost/products/x',
                'items' => [['variant_id' => $this->variant->id, 'quantity' => 2, 'price' => 1]], // preço do navegador é ignorado
            ])->assertNoContent();

        $ev = AnalyticsEvent::where('name', 'add_to_cart')->latest('id')->first();
        $this->assertSame(25980, $ev->value);
        $this->assertSame('mobile', VisitorSession::find($ev->visitor_session_id)->device);

        $meta = TrackingDispatch::where('platform', 'meta')->where('event_id', 'evt-12345678')->first();
        $this->assertSame('AddToCart', $meta->event_name);
        $this->assertSame(259.8, $meta->payload['custom_data']['value']);
        $this->assertSame([(string) $this->variant->id], $meta->payload['custom_data']['content_ids']);
        $this->assertSame([hash('sha256', 'cliente@example.com')], $meta->payload['user_data']['em']);
        $this->assertSame('fb.1.1700000000000.ABC', $meta->payload['user_data']['fbc']);
        $this->assertNotEmpty($meta->payload['user_data']['external_id']);
        $this->assertTrue(TrackingDispatch::where('platform', 'tiktok')->where('event_id', 'evt-12345678')->exists());

        // Envio em lote
        app(TrackingService::class)->flush();
        $this->assertSame('sent', $meta->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com') && str_contains($r->url(), '/1234567890/events')
            && $r['data'][0]['event_id'] === 'evt-12345678' && $r['data'][0]['action_source'] === 'website');
    }

    public function test_beacon_ignores_purchase_bots_and_invalid_events(): void
    {
        $before = AnalyticsEvent::count();
        $this->postJson('/t', ['name' => 'purchase', 'event_id' => 'evt-aaaaaaaa'])->assertNoContent();
        $this->withHeader('User-Agent', 'facebookexternalhit/1.1')->postJson('/t', ['name' => 'page_view', 'event_id' => 'evt-bbbbbbbb'])->assertNoContent();
        $this->postJson('/t', ['name' => 'hack', 'event_id' => 'evt-cccccccc'])->assertNoContent();
        $this->assertSame($before, AnalyticsEvent::count());
    }

    public function test_purchase_is_sent_once_with_order_event_id_and_full_match_data(): void
    {
        $order = Order::create([
            'customer_name' => 'Maria Silva Souza', 'customer_email' => 'maria@example.com', 'customer_phone' => '11987654321',
            'customer_document' => '52998224725', 'shipping_address' => ['zip' => '01001000', 'city' => 'São Paulo', 'state' => 'SP', 'street' => 'X', 'number' => '1', 'neighborhood' => 'Sé'],
            'subtotal' => 12990, 'total' => 12990, 'payment_method' => 'pix', 'payment_status' => 'paid', 'status' => 'paid', 'paid_at' => now(),
            'fbp' => 'fb.1.1.2', 'fbc' => 'fb.1.1.CLICK', 'client_ip' => '200.1.2.3', 'user_agent' => 'Mozilla/5.0', 'visitor_id' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
        ]);
        $order->items()->create(['product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id, 'title' => 'Vestido', 'unit_price' => 12990, 'quantity' => 1, 'total' => 12990]);

        $svc = app(TrackingService::class);
        $svc->sendPurchase($order);
        $svc->sendPurchase($order->fresh()); // idempotente

        $metaCalls = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'graph.facebook.com'));
        $this->assertCount(1, $metaCalls);
        $e = $metaCalls->first()[0]['data'][0];
        $this->assertSame('Purchase', $e['event_name']);
        $this->assertSame($order->event_id, $e['event_id']);
        $this->assertSame(129.9, $e['custom_data']['value']);
        $this->assertSame('BRL', $e['custom_data']['currency']);
        foreach (['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id', 'client_ip_address', 'client_user_agent', 'fbp', 'fbc'] as $k) {
            $this->assertArrayHasKey($k, $e['user_data'], "faltou $k");
        }

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'business-api.tiktok.com') && $r['data'][0]['event'] === 'CompletePayment' && $r['data'][0]['event_id'] === $order->event_id);
        $this->assertNotNull($order->fresh()->purchase_event_sent_at);
    }

    public function test_utmify_payload_maps_status_and_utms(): void
    {
        $order = new Order(['number' => 10050, 'payment_method' => 'credit_card', 'payment_status' => 'paid', 'total' => 15000, 'gateway_fee' => 600,
            'customer_name' => 'A B', 'customer_email' => 'a@b.com', 'customer_phone' => '11999999999', 'customer_document' => '52998224725',
            'utm_source' => 'facebook', 'utm_campaign' => 'bf', 'attribution' => ['src' => 'x']]);
        $order->created_at = now();
        $order->paid_at = now();
        $order->setRelation('items', collect());

        $p = Utmify::payload($order);
        $this->assertSame('paid', $p['status']);
        $this->assertSame('credit_card', $p['paymentMethod']);
        $this->assertSame('facebook', $p['trackingParameters']['utm_source']);
        $this->assertSame(14400, $p['commission']['userCommissionInCents']);
    }

    public function test_session_source_classification(): void
    {
        $this->assertSame('meta', TrackingService::classifySource(['fbclid' => 'x'], null));
        $this->assertSame('google_ads', TrackingService::classifySource(['gclid' => 'x'], null));
        $this->assertSame('organico', TrackingService::classifySource([], 'https://www.google.com/'));
        $this->assertSame('direto', TrackingService::classifySource([], null));
        $this->assertSame('email', TrackingService::classifySource(['utm_source' => 'brevo', 'utm_medium' => 'email'], null));
    }
}
