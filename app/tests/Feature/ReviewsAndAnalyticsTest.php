<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Services\Analytics\StoreAnalytics;
use App\Services\Orders\OrderManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReviewsAndAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutVite();
        $this->product = Product::create(['title' => 'Vestido Avaliado', 'slug' => 'vestido-avaliado-'.uniqid(), 'status' => 'active']);
        $this->product->variants()->create(['title' => 'P', 'price' => 10000, 'stock' => 10]);
    }

    private function order(string $status = 'delivered'): Order
    {
        $o = Order::create([
            'customer_name' => 'Maria Souza', 'customer_email' => 'maria@example.com', 'customer_phone' => '11987654321', 'customer_document' => '52998224725',
            'shipping_address' => ['zip' => '01001000'], 'subtotal' => 10000, 'total' => 10000, 'payment_method' => 'pix',
            'status' => $status, 'payment_status' => 'paid', 'paid_at' => now(),
        ]);
        $o->items()->create(['product_id' => $this->product->id, 'title' => 'Vestido Avaliado', 'unit_price' => 10000, 'quantity' => 1, 'total' => 10000]);

        return $o;
    }

    public function test_rating_average_follows_approved_reviews_only(): void
    {
        Review::create(['product_id' => $this->product->id, 'author' => 'A', 'rating' => 5, 'status' => 'approved']);
        Review::create(['product_id' => $this->product->id, 'author' => 'B', 'rating' => 4, 'status' => 'approved']);
        $hidden = Review::create(['product_id' => $this->product->id, 'author' => 'C', 'rating' => 1, 'status' => 'pending']);

        $this->product->refresh();
        $this->assertSame(2, $this->product->rating_count);
        $this->assertSame(4.5, $this->product->rating_avg);

        $hidden->update(['status' => 'approved']);
        $this->assertSame(3, $this->product->fresh()->rating_count);
    }

    public function test_product_page_shows_reviews_and_structured_rating(): void
    {
        Review::create(['product_id' => $this->product->id, 'author' => 'Ana Paula Lima', 'rating' => 5, 'body' => 'Tecido maravilhoso', 'status' => 'approved', 'reply' => 'Obrigada!']);

        $this->get('/products/'.$this->product->slug)->assertOk()
            ->assertSee('Avaliações de clientes')
            ->assertSee('"aggregateRating"', false)
            ->assertSee('Ana L.');

        $this->getJson('/products/'.$this->product->slug.'/avaliacoes?nota=5')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.reply', 'Obrigada!');
    }

    public function test_buyer_can_review_and_it_waits_moderation_by_default(): void
    {
        $order = $this->order();
        $this->get(route('reviews.form', $order->public_token))->assertOk()->assertSee('Vestido Avaliado');

        $this->post(route('reviews.store', $order->public_token), ['product_id' => $this->product->id, 'rating' => 5, 'author' => 'Maria', 'body' => 'Amei!'])
            ->assertRedirect();

        $r = Review::where('order_id', $order->id)->first();
        $this->assertTrue($r->verified);
        $this->assertSame('pending', $r->status);

        // não avalia o mesmo produto duas vezes
        $this->post(route('reviews.store', $order->public_token), ['product_id' => $this->product->id, 'rating' => 1, 'author' => 'Maria']);
        $this->assertSame(1, Review::where('order_id', $order->id)->count());
    }

    public function test_auto_approve_setting_and_unpaid_order_blocked(): void
    {
        Setting::set('reviews.auto_approve_min', '4');
        $order = $this->order();
        $this->post(route('reviews.store', $order->public_token), ['product_id' => $this->product->id, 'rating' => 5, 'author' => 'Maria']);
        $this->assertSame('approved', Review::where('order_id', $order->id)->value('status'));

        $unpaid = $this->order();
        $unpaid->update(['paid_at' => null, 'payment_status' => 'pending', 'status' => 'pending']);
        $this->get(route('reviews.form', $unpaid->public_token))->assertForbidden();
    }

    public function test_order_manager_ship_and_cancel_flow(): void
    {
        $order = $this->order('paid');
        $m = app(OrderManager::class);

        $m->markShipped($order, 'aa123456789br', 'Correios');
        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame('AA123456789BR', $order->fresh()->tracking_code);
        $this->assertStringContainsString('correios', $order->fresh()->tracking_url);

        $csv = $m->supplierCsv(collect([$order->fresh()]));
        $this->assertStringContainsString('AA123456789BR', $csv);
        $this->assertStringContainsString('Vestido Avaliado', $csv);

        $m->cancel($order->fresh(), 'cliente desistiu', restock: false);
        $this->assertSame('refunded', $order->fresh()->status); // já pago → reembolso
    }

    public function test_analytics_summary_counts_paid_orders_and_pix_rate(): void
    {
        $this->order('paid');
        $pending = $this->order('pending');
        $pending->update(['paid_at' => null, 'payment_status' => 'pending', 'gateway_uuid' => 'x']);

        $s = (new StoreAnalytics('today'))->summary();
        $this->assertGreaterThanOrEqual(1, $s['paid_orders']);
        $this->assertGreaterThanOrEqual(10000, $s['revenue']);
        $this->assertNotEmpty((new StoreAnalytics('7d'))->daily());
        $this->assertSame('Sessões', (new StoreAnalytics('7d'))->funnel()[0]['label']);
    }
}
