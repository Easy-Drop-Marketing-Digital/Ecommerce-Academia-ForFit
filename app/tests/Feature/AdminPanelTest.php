<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Abre cada tela do admin como dono e garante que nenhuma quebra. */
class AdminPanelTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::create(['name' => 'Dona', 'email' => 'dona-'.uniqid().'@example.com', 'password' => 'senha-de-teste-123', 'role' => 'owner']);
    }

    private function seedData(): array
    {
        $cat = Category::create(['name' => 'Teste Admin', 'slug' => 'teste-admin-'.uniqid()]);
        $p = Product::create(['title' => 'Produto Admin', 'slug' => 'produto-admin-'.uniqid(), 'status' => 'active']);
        $p->options()->create(['name' => 'Tamanho', 'position' => 1, 'values' => ['P', 'M']]);
        $v = $p->variants()->create(['title' => 'P', 'option1' => 'P', 'price' => 9990, 'stock' => 5]);
        $cat->products()->attach($p->id, ['position' => 0]);
        $c = Customer::create(['name' => 'Cliente Admin', 'email' => 'cli-'.uniqid().'@example.com']);
        $o = Order::create([
            'customer_id' => $c->id, 'customer_name' => 'Cliente Admin', 'customer_email' => $c->email, 'customer_phone' => '11987654321',
            'customer_document' => '52998224725', 'shipping_address' => ['zip' => '01001000', 'street' => 'Rua', 'number' => '1', 'neighborhood' => 'Sé', 'city' => 'São Paulo', 'state' => 'SP'],
            'subtotal' => 9990, 'total' => 9990, 'payment_method' => 'pix', 'status' => 'paid', 'payment_status' => 'paid', 'paid_at' => now(),
        ]);
        $o->items()->create(['product_id' => $p->id, 'variant_id' => $v->id, 'title' => 'Produto Admin', 'variant_title' => 'P', 'unit_price' => 9990, 'quantity' => 1, 'total' => 9990]);
        $o->log('created', 'Pedido criado');
        $d = Discount::create(['title' => 'Cupom teste', 'code' => 'T'.uniqid(), 'type' => 'percentage', 'value' => 10, 'is_active' => true]);
        $r = Review::create(['product_id' => $p->id, 'author' => 'Ana Lima', 'rating' => 5, 'body' => 'Amei', 'status' => 'approved']);

        return compact('cat', 'p', 'c', 'o', 'd', 'r');
    }

    public function test_every_admin_screen_renders(): void
    {
        $m = $this->seedData();
        $menu = Menu::firstOrCreate(['handle' => 'main'], ['name' => 'Menu principal']);
        $page = Page::firstOrCreate(['slug' => 'pagina-admin-teste'], ['title' => 'Teste', 'content' => '<p>x</p>']);

        $urls = [
            '/admin',
            '/admin/orders', '/admin/orders/'.$m['o']->id,
            '/admin/customers', '/admin/customers/'.$m['c']->id.'/edit',
            '/admin/carts',
            '/admin/products', '/admin/products/create', '/admin/products/'.$m['p']->id.'/edit',
            '/admin/categories', '/admin/categories/'.$m['cat']->id.'/edit',
            '/admin/estoque',
            '/admin/reviews', '/admin/reviews/'.$m['r']->id.'/edit',
            '/admin/discounts', '/admin/discounts/create', '/admin/discounts/'.$m['d']->id.'/edit',
            '/admin/banners', '/admin/home-sections',
            '/admin/menus', '/admin/menus/'.$menu->id.'/edit',
            '/admin/pages', '/admin/pages/'.$page->id.'/edit',
            '/admin/shipping-methods', '/admin/users', '/admin/configuracoes', '/admin/email-logs', '/admin/redirects', '/admin/saude', '/admin/profile',
        ];

        $failures = [];
        foreach ($urls as $url) {
            $res = $this->actingAs($this->owner)->get($url);
            if ($res->status() !== 200) {
                $msg = $res->exception ? get_class($res->exception).': '.mb_substr($res->exception->getMessage(), 0, 300) : 'HTTP '.$res->status();
                $failures[] = "$url → $msg";
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_staff_cannot_open_settings_or_users(): void
    {
        $staff = User::create(['name' => 'Equipe', 'email' => 'eq-'.uniqid().'@example.com', 'password' => 'senha-de-teste-123', 'role' => 'staff']);

        $this->actingAs($staff)->get('/admin/orders')->assertOk();
        $this->actingAs($staff)->get('/admin/configuracoes')->assertForbidden();
        $this->actingAs($staff)->get('/admin/users')->assertForbidden();
        $this->actingAs($staff)->get('/admin/discounts')->assertForbidden();
    }

    public function test_settings_page_saves_values_and_keeps_secret_when_left_blank(): void
    {
        \Illuminate\Support\Facades\DB::table('settings')->whereIn('key', ['store.whatsapp', 'nixfy.private_key'])->delete();
        \App\Models\Setting::flushMemo();
        \Illuminate\Support\Facades\Cache::forget('settings.all');
        $this->actingAs($this->owner);

        $page = \Livewire\Livewire::test(\App\Filament\Pages\Settings::class)->assertSee('Dados da loja')->assertSee('Pagamentos — Nixfy');
        $page->callAction('editGroup', ['store__whatsapp' => '(11) 99999-0000'], arguments: ['group' => 'store'])->assertHasNoActionErrors();
        $page->callAction('editGroup', ['nixfy__private_key' => 'sk_live_segredo_1234'], arguments: ['group' => 'nixfy'])->assertHasNoActionErrors();

        $this->assertSame('(11) 99999-0000', \App\Support\StoreSettings::get('store.whatsapp'));
        $this->assertSame('sk_live_segredo_1234', \App\Support\StoreSettings::get('nixfy.private_key'));
        $this->assertTrue((bool) \Illuminate\Support\Facades\DB::table('settings')->where('key', 'nixfy.private_key')->value('is_encrypted'));

        // Salvar de novo com o segredo em branco mantém o valor anterior
        \Livewire\Livewire::test(\App\Filament\Pages\Settings::class)->callAction('editGroup', ['nixfy__private_key' => ''], arguments: ['group' => 'nixfy']);
        \App\Models\Setting::flushMemo();
        $this->assertSame('sk_live_segredo_1234', \App\Support\StoreSettings::get('nixfy.private_key'));
    }

    public function test_customers_cannot_reach_admin(): void
    {
        $this->get('/admin/orders')->assertRedirect();
    }
}
