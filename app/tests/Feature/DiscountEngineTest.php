<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\DiscountTarget;
use App\Models\Product;
use App\Services\Checkout\DiscountEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscountEngineTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Discount::query()->update(['is_active' => false]);
        DB::table('settings')->where('key', 'like', 'nixfy.%')->delete();
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function line(int $vid, int $pid, int $price, int $qty): array
    {
        return ['variant_id' => $vid, 'product_id' => $pid, 'category_ids' => [], 'price' => $price, 'qty' => $qty, 'title' => "P$pid"];
    }

    public function test_buy_2_get_1_free_discounts_cheapest_unit(): void
    {
        Discount::create(['title' => 'Compre 2 leve 3', 'type' => 'buy_x_get_y', 'is_active' => true, 'rules' => ['buy_qty' => 2, 'get_qty' => 1, 'get_percent' => 100]]);
        $r = (new DiscountEngine)->calculate([$this->line(1, 1, 10000, 2), $this->line(2, 2, 6000, 1)]);

        $this->assertSame(6000, $r['items_discount']);
        $this->assertSame(6000, $r['line_discounts'][2]);
    }

    public function test_tiered_progressive_discount(): void
    {
        Discount::create(['title' => 'Progressivo', 'type' => 'tiered', 'is_active' => true, 'rules' => ['tiers' => [['min_qty' => 2, 'percent' => 10], ['min_qty' => 3, 'percent' => 15]]]]);
        $e = new DiscountEngine;

        $this->assertSame(0, $e->calculate([$this->line(1, 1, 10000, 1)])['items_discount']);
        $this->assertSame(2000, $e->calculate([$this->line(1, 1, 10000, 2)])['items_discount']);
        $this->assertSame(4500, $e->calculate([$this->line(1, 1, 10000, 3)])['items_discount']);
    }

    public function test_bundle_fixed_price_combo(): void
    {
        $a = Product::create(['title' => 'Blusa', 'slug' => 'b-'.uniqid()]);
        $b = Product::create(['title' => 'Calça', 'slug' => 'c-'.uniqid()]);
        Discount::create(['title' => 'Look completo por R$ 150', 'type' => 'bundle', 'is_active' => true,
            'rules' => ['items' => [['product_id' => $a->id, 'qty' => 1], ['product_id' => $b->id, 'qty' => 1]], 'price' => 15000]]);

        $r = (new DiscountEngine)->calculate([$this->line(1, $a->id, 9000, 1), $this->line(2, $b->id, 11000, 1)]);
        $this->assertSame(5000, $r['items_discount']);

        // Só um dos itens: sem combo
        $this->assertSame(0, (new DiscountEngine)->calculate([$this->line(1, $a->id, 9000, 2)])['items_discount']);
    }

    public function test_only_best_non_combinable_applies_but_combinables_stack(): void
    {
        Discount::create(['title' => 'Auto 10%', 'type' => 'percentage', 'value' => 10, 'is_active' => true]);
        Discount::create(['title' => 'Cupom 20', 'code' => 'VINTE', 'type' => 'fixed_amount', 'value' => 2000, 'is_active' => true]);
        Discount::create(['title' => 'Frete grátis', 'type' => 'free_shipping', 'is_active' => true, 'combinable' => true]);

        $r = (new DiscountEngine)->calculate([$this->line(1, 1, 30000, 1)], ['code' => 'vinte', 'shipping' => 1990]);

        $this->assertSame(3000, $r['items_discount']); // 10% (R$ 30) > cupom R$ 20
        $this->assertSame('superseded', $r['code_status']);
        $this->assertTrue($r['free_shipping']);
    }

    public function test_targets_limit_discount_to_category_products(): void
    {
        $cat = \App\Models\Category::create(['name' => 'Vestidos', 'slug' => 'v-'.uniqid()]);
        $dress = Product::create(['title' => 'Vestido', 'slug' => 'd-'.uniqid()]);
        $cat->products()->attach($dress->id);
        $d = Discount::create(['title' => 'Vestidos 50%', 'type' => 'percentage', 'value' => 50, 'is_active' => true, 'applies_to' => 'categories']);
        DiscountTarget::create(['discount_id' => $d->id, 'target_type' => \App\Models\Category::class, 'target_id' => $cat->id]);

        $r = (new DiscountEngine)->calculate([$this->line(1, $dress->id, 10000, 1), $this->line(2, 999999, 10000, 1)]);
        $this->assertSame(5000, $r['items_discount']);
        $this->assertSame(5000, $r['line_discounts'][1]);
        $this->assertArrayNotHasKey(2, $r['line_discounts']);
    }

    public function test_expired_or_unknown_coupon_is_invalid(): void
    {
        Discount::create(['title' => 'Velho', 'code' => 'VELHO', 'type' => 'percentage', 'value' => 10, 'is_active' => true, 'ends_at' => now()->subDay()]);
        $e = new DiscountEngine;

        $this->assertSame('invalid', $e->calculate([$this->line(1, 1, 10000, 1)], ['code' => 'VELHO'])['code_status']);
        $this->assertSame('invalid', $e->calculate([$this->line(1, 1, 10000, 1)], ['code' => 'NAOEXISTE'])['code_status']);
    }
}
