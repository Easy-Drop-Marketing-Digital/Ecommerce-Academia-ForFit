<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\ImageOptimizer;
use App\Support\SystemHealth;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Itens de produção: segurança, saúde da loja, fotos locais, seeder seguro. */
class GoLiveTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutVite();
    }

    private function owner(): User
    {
        return User::create(['name' => 'Dona', 'email' => 'dona-'.uniqid().'@example.com', 'password' => 'senha-de-teste-123', 'role' => 'owner']);
    }

    public function test_security_headers_on_store_and_admin(): void
    {
        foreach (['/', '/admin/login'] as $url) {
            $this->get($url)->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
    }

    public function test_two_factor_is_required_when_enabled(): void
    {
        $owner = $this->owner();
        config(['store.admin_require_2fa' => false]);
        $this->actingAs($owner)->get('/admin/orders')->assertOk();

        config(['store.admin_require_2fa' => true]);
        $this->actingAs($owner)->get('/admin/orders')->assertRedirect();
        $this->assertStringContainsString('multi-factor', $this->actingAs($owner)->get('/admin/orders')->headers->get('Location'));
    }

    public function test_health_flags_stopped_cron_and_dashboard_warns(): void
    {
        config(['services.nixfy.public_key' => 'pk', 'services.nixfy.private_key' => 'sk', 'services.nixfy.webhook_secret' => 'wh']);
        Cache::forget(SystemHealth::HEARTBEAT);

        $cron = collect(SystemHealth::checks(fresh: true))->firstWhere('key', 'cron');
        $this->assertSame('error', $cron['status']);
        $this->actingAs($this->owner())->get('/admin')->assertOk()->assertSee('Tarefas automáticas (cron)');

        $this->artisan('schedule:run')->assertSuccessful(); // o próprio agendador marca o sinal de vida
        $this->assertSame('ok', collect(SystemHealth::checks(fresh: true))->firstWhere('key', 'cron')['status']);
        $this->assertSame('ok', collect(SystemHealth::checks(fresh: true))->firstWhere('key', 'nixfy')['status']);
    }

    public function test_health_page_renders_and_cache_can_be_cleared(): void
    {
        $this->actingAs($this->owner());
        \Livewire\Livewire::test(\App\Filament\Pages\Health::class)
            ->assertSee('Saúde da loja')->assertSee('Fotos dos produtos')
            ->callAction('flushCache')->assertNotified('Cache da loja limpo');
    }

    public function test_localize_copies_shopify_photos_as_sized_webp(): void
    {
        Storage::fake('public');
        $png = (function () { $im = imagecreatetruecolor(1600, 2000); ob_start(); imagepng($im); return ob_get_clean(); })();
        Http::fake(['cdn.shopify.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $p = Product::create(['title' => 'Foto', 'slug' => 'foto-'.uniqid(), 'status' => 'active']);
        $img = $p->images()->create(['path' => 'https://cdn.shopify.com/s/files/1/abc/foto.jpg?v=1&width=600', 'position' => 1]);

        $this->artisan('images:localize')->assertSuccessful();

        $img->refresh();
        $this->assertSame("products/{$p->id}/{$img->id}-r1200.webp", $img->path);
        $this->assertSame([1200, 1500], [$img->width, $img->height]);
        foreach (ImageOptimizer::WIDTHS as $w) {
            Storage::disk('public')->assertExists("products/{$p->id}/{$img->id}-r{$w}.webp");
        }
        $this->assertStringEndsWith("{$img->id}-r400.webp", img_url($img->path, 360));
        Http::assertSent(fn ($r) => ! str_contains($r->url(), 'width='));

        // remover a foto apaga os 5 tamanhos
        $img->delete();
        Storage::disk('public')->assertMissing("products/{$p->id}/{$img->id}-r600.webp");
    }

    public function test_default_seeder_never_creates_a_known_admin_login(): void
    {
        Storage::fake('public');
        Http::fake(); // garante que o seeder não acessa a internet
        $before = User::count();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->assertSame($before, User::count());
        $this->assertFalse(User::where('email', 'test@example.com')->exists());
    }
}
