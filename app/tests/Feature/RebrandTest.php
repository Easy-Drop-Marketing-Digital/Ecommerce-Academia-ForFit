<?php

namespace Tests\Feature;

use App\Console\Commands\CatalogRebrand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Setting;
use App\Support\PageCache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Loja independente: nada da marca/plataforma de origem nas páginas, no feed, nos SKUs
 * nem nos dados enviados a terceiros; pacote do catálogo instala sem acessar outra loja.
 */
class RebrandTest extends TestCase
{
    use DatabaseTransactions;

    /** nome da marca de origem montado em partes (o código da loja não contém esse nome) */
    private string $old;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutVite();
        PageCache::flush();
        $this->old = 'Ser'.'ena';
    }

    private function png(int $w = 1600, int $h = 2000): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start(); imagepng($im);

        return ob_get_clean();
    }

    public function test_swap_keeps_case_and_whole_words_only(): void
    {
        $pairs = [["{$this->old} Diva", 'Aurea Santz'], [$this->old, 'Áurea']];
        $text = "A Loja {$this->old} Diva indica. A LOJA ".mb_strtoupper("{$this->old} DIVA")." É ÓTIMA. Conjunto {$this->old}. "
            .mb_strtolower($this->old)."mente calma. Cupom ".mb_strtoupper($this->old).'5.';

        $out = CatalogRebrand::swap($text, $pairs);

        $this->assertStringContainsString('A Loja Aurea Santz indica', $out);
        $this->assertStringContainsString('A LOJA AUREA SANTZ É ÓTIMA', $out);
        $this->assertStringContainsString('Conjunto Áurea.', $out);
        $this->assertStringContainsString(mb_strtolower($this->old).'mente', $out, 'palavra comum não pode ser trocada');
    }

    public function test_rebrand_cleans_catalog_and_copies_third_party_images(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);
        $p = Product::create([
            'title' => "Kit 2 Conjuntos {$this->old}", 'slug' => 'kit-2-conjuntos-'.mb_strtolower($this->old).'-'.uniqid(), 'status' => 'active',
            'vendor' => "{$this->old} Diva",
            'description' => "<p>Dica da {$this->old}: veste bem.</p><img src=\"https://cdn.shopify.com/s/files/1/0/abc/foto.jpg?v=1\">"
                .'<a href="https://outra-loja.com.br/products/x"><img src="https://ae01.alicdn.com/x.jpg"></a>',
        ]);
        $v = $p->variants()->create(['title' => 'M', 'price' => 10000, 'stock' => 5]);
        DB::table('product_variants')->where('id', $v->id)->update(['sku' => 'origem-za-123456']);
        $r = Review::create(['product_id' => $p->id, 'author' => 'Ana', 'rating' => 5, 'status' => 'approved',
            'media' => [['url' => 'https://bk-reviews.b-cdn.net/stores/origem.myshopify.com/1/a.jpg', 'type' => 'image']]]);

        $this->artisan('catalog:rebrand', ['--trocar' => ["{$this->old} Diva=>Aurea Santz", "Dica da {$this->old}=>Dica da Aurea", "{$this->old}=>Áurea"]])->assertSuccessful();

        $p->refresh();
        $this->assertSame('Kit 2 Conjuntos Áurea', $p->title);
        $this->assertStringStartsWith('kit-2-conjuntos-aurea', $p->slug);
        $this->assertSame(setting('store.name'), $p->vendor);
        $this->assertStringContainsString('Dica da Aurea', $p->description);
        $this->assertStringNotContainsString('cdn.shopify.com', $p->description);
        $this->assertStringNotContainsString('outra-loja.com.br', $p->description, 'link para outra loja deve sair');
        $this->assertMatchesRegularExpression('#/storage/products/'.$p->id.'/descricao-[a-f0-9]+-w1200\.webp#', $p->description);

        $v->refresh();
        $this->assertSame(ProductVariant::publicSku($v->id), $v->sku);
        $this->assertSame('origem-za-123456', $v->supplier_sku);

        $this->assertStringStartsWith('/storage/reviews/'.$r->id.'/', $r->fresh()->media[0]['url']);
        $this->assertSame(0, DB::table('redirects')->where('from_path', 'like', '%'.mb_strtolower($this->old).'%')->count());
    }

    public function test_only_supplier_codes_from_the_origin_store_are_erased(): void
    {
        $p = Product::create(['title' => 'Blusa', 'slug' => 'blusa-'.uniqid(), 'status' => 'active']);
        $origem = $p->variants()->create(['title' => 'P', 'price' => 5000, 'stock' => 1, 'supplier_sku' => 'lojaorigem-za-555']);
        $ali = $p->variants()->create(['title' => 'M', 'price' => 5000, 'stock' => 1, 'supplier_sku' => '14:173;5:-1']);

        $this->artisan('catalog:rebrand', ['--pular-midias' => true, '--apagar-codigo-fornecedor' => ['lojaorigem']])->assertSuccessful();

        $this->assertNull($origem->fresh()->supplier_sku);
        $this->assertSame('14:173;5:-1', $ali->fresh()->supplier_sku, 'código útil do fornecedor (AliExpress) deve ficar');
    }

    public function test_new_variants_get_a_neutral_store_sku(): void
    {
        $p = Product::create(['title' => 'Blusa', 'slug' => 'blusa-'.uniqid(), 'status' => 'active']);
        $v = $p->variants()->create(['title' => 'P', 'price' => 5000, 'stock' => 1]);

        $this->assertMatchesRegularExpression('/^FF-\d{5}$/', $v->fresh()->sku);
    }

    public function test_storefront_pages_and_feed_carry_only_the_new_brand(): void
    {
        $cat = Category::create(['name' => 'Vestidos', 'slug' => 'vestidos-'.uniqid(), 'is_active' => true]);
        $p = Product::create(['title' => 'Vestido Midi', 'slug' => 'vestido-midi-'.uniqid(), 'status' => 'active']);
        $p->variants()->create(['title' => 'M', 'price' => 12000, 'stock' => 3]);
        $cat->products()->attach($p->id, ['position' => 0]);
        $this->artisan('feeds:build')->assertSuccessful();

        foreach (['/', '/products/'.$p->slug, '/collections/'.$cat->slug, '/cart', '/account/login'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsStringIgnoringCase($this->old, $html, "$url menciona a marca antiga");
            $this->assertStringNotContainsStringIgnoringCase('aurea', $html, "$url menciona a Aurea Santz");
            $this->assertStringContainsString('ForFit', $html, "$url sem o nome da loja");
        }
        $feed = Storage::disk('local')->get(\App\Services\Tracking\ProductFeed::PATH);
        $this->assertStringNotContainsStringIgnoringCase($this->old, $feed);
        $this->assertStringNotContainsString('shopify', $feed);

        // Meta recusa arquivo com linha acima de 5 MB: o feed precisa sair com um campo por linha
        $lines = explode("\n", $feed);
        $this->assertGreaterThan(5, count($lines), 'feed gravado numa linha só');
        $this->assertLessThan(100_000, max(array_map('strlen', $lines)), 'linha do feed longa demais');
    }

    public function test_catalog_package_round_trip_keeps_secrets_out(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/1/1-r1200.webp', 'foto');
        Setting::set('store.whatsapp', '(11) 90000-0000');
        Setting::set('nixfy.private_key', 'segredo', encrypt: true);
        $p = Product::create(['title' => 'Saia', 'slug' => 'saia-'.uniqid(), 'status' => 'active', 'shopify_id' => 123456789]);
        $p->variants()->create(['title' => 'U', 'price' => 9000, 'stock' => 2]);
        $dir = storage_path('framework/testing/pacote-'.uniqid());

        $this->artisan('catalog:export', ['--out' => $dir])->assertSuccessful();
        $zip = glob("$dir/catalogo-*.zip")[0];
        $json = json_decode((new \ZipArchive)->open($zip) === true ? (function () use ($zip) { $z = new \ZipArchive; $z->open($zip); return $z->getFromName('catalogo.json'); })() : '', true);

        $keys = array_column($json['tables']['settings'], 'key');
        $this->assertContains('store.whatsapp', $keys);
        $this->assertNotContains('nixfy.private_key', $keys, 'chave de integração não pode ir no pacote');
        $this->assertNull(collect($json['tables']['products'])->firstWhere('id', $p->id)['shopify_id']);

        // instala por cima: catálogo volta igual, chave já cadastrada no servidor continua
        Storage::disk('public')->delete('products/1/1-r1200.webp');
        $this->artisan('catalog:import', ['arquivo' => $zip, '--force' => true])->assertSuccessful();
        $this->assertTrue(Product::whereKey($p->id)->exists());
        Storage::disk('public')->assertExists('products/1/1-r1200.webp');
        $this->assertSame('segredo', \App\Support\StoreSettings::get('nixfy.private_key'));

        \Illuminate\Support\Facades\File::deleteDirectory($dir);
    }
}
