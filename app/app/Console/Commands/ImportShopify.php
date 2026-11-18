<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Importa catálogo público da loja Shopify (products.json / collections.json).
 *
 *   php artisan shopify:import https://loja-de-origem.com.br --stock=1000 --download-images
 *
 * Idempotente: usa shopify_id para atualizar em vez de duplicar.
 * --download-images copia as fotos para a hospedagem em WebP (= php artisan images:localize).
 *
 * Catálogo vindo de OUTRA marca: depois de importar, rode php artisan catalog:rebrand (troca nomes, SKUs,
 * copia imagens das descrições) — e em produção instale pelo pacote (catalog:export), nunca importando direto.
 */
class ImportShopify extends Command
{
    protected $signature = 'shopify:import {store : URL da loja} {--stock=1000 : Estoque inicial por variante} {--download-images} {--keep-stock : Não sobrescreve estoque de variantes já existentes}';

    protected $description = 'Importa produtos, variantes, imagens e coleções de uma loja Shopify';

    private const COLOR_NAMES = '/^(cor|cores|color|colour|escolha (a sua|as|a) cor(es)?:?|cores dispon[ií]veis:?)$/iu';

    private const SIZE_NAMES = '/^(tamanho|tamanhos|size|escolha o (seu )?tamanho:?)$/iu';

    public function handle(): int
    {
        $base = rtrim($this->argument('store'), '/');
        $stock = (int) $this->option('stock');

        $products = $this->fetchAll("$base/products.json", 'products');
        $this->info(count($products).' produtos encontrados');

        $bar = $this->output->createProgressBar(count($products));
        $map = []; // shopify_id => local id
        foreach ($products as $sp) {
            $map[$sp['id']] = $this->importProduct($sp, $stock);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $collections = $this->fetchAll("$base/collections.json", 'collections');
        foreach ($collections as $i => $sc) {
            $cat = Category::updateOrCreate(['shopify_id' => $sc['id']], [
                'name' => $sc['title'],
                'slug' => $sc['handle'],
                'description' => $this->cleanHtml($sc['body_html'] ?? null),
                'image' => $sc['image']['src'] ?? null,
                'position' => $i,
                'is_active' => true,
            ]);
            $items = $this->fetchAll("$base/collections/{$sc['handle']}/products.json", 'products');
            $sync = [];
            foreach ($items as $pos => $p) {
                if (isset($map[$p['id']])) {
                    $sync[$map[$p['id']]] = ['position' => $pos];
                }
            }
            $cat->products()->sync($sync);
            $this->line("Coleção {$sc['title']}: ".count($sync).' produtos');
        }

        if ($this->option('download-images')) {
            $this->call('images:localize');
        }

        $this->info('Importação concluída.');

        return self::SUCCESS;
    }

    private function fetchAll(string $url, string $key): array
    {
        $all = [];
        for ($page = 1; $page <= 50; $page++) {
            $res = Http::retry(3, 1500)->timeout(60)
                ->withUserAgent('Mozilla/5.0 (compatible; StoreImporter/1.0)')
                ->get($url, ['limit' => 250, 'page' => $page]);
            $batch = $res->json($key) ?? [];
            if (! $batch) {
                break;
            }
            array_push($all, ...$batch);
            if (count($batch) < 250) {
                break;
            }
        }

        return $all;
    }

    private function importProduct(array $sp, int $stock): int
    {
        return DB::transaction(function () use ($sp, $stock) {
            $type = trim((string) $sp['product_type']);
            $product = Product::withTrashed()->updateOrCreate(['shopify_id' => $sp['id']], [
                'title' => trim($sp['title']),
                'slug' => $sp['handle'],
                'description' => $this->cleanHtml($sp['body_html']),
                'vendor' => setting('store.name'), // a marca exibida é sempre a desta loja
                // No tema atual o "tipo" foi usado como cópia do título; só mantemos quando é categoria real.
                'product_type' => ($type === '' || Str::length($type) > 40 || $type === trim($sp['title'])) ? null : $type,
                'tags' => $sp['tags'] ?: null,
                'status' => 'active',
                'published_at' => $sp['published_at'] ?? now(),
                'deleted_at' => null,
            ]);

            // Imagens
            $imageMap = [];
            foreach ($sp['images'] as $img) {
                $local = ProductImage::firstOrNew(['shopify_id' => $img['id']]);
                $local->fill([
                    'product_id' => $product->id,
                    'alt' => Str::limit(trim(explode("\n", (string) ($img['alt'] ?? ''))[0]) ?: $product->title, 250, ''),
                    'position' => $img['position'],
                ]);
                // foto já copiada para a hospedagem (images:localize) não volta a apontar para a Shopify
                if (! $local->exists || str_starts_with((string) $local->path, 'http')) {
                    $local->fill(['path' => $img['src'], 'width' => $img['width'] ?? null, 'height' => $img['height'] ?? null]);
                }
                $local->save();
                $imageMap[$img['id']] = $local->id;
            }

            // Opções (nomes normalizados: Cor / Tamanho)
            $product->options()->delete();
            foreach ($sp['options'] as $opt) {
                if (count($opt['values']) === 1 && in_array($opt['values'][0], ['Default Title', 'Padrão'])) {
                    continue;
                }
                $product->options()->create([
                    'name' => $this->normalizeOptionName($opt['name']),
                    'display_type' => preg_match(self::COLOR_NAMES, trim($opt['name'])) ? 'swatch' : 'button',
                    'position' => $opt['position'],
                    'values' => array_values($opt['values']),
                ]);
            }

            // Variantes
            foreach ($sp['variants'] as $sv) {
                $existing = $product->variants()->where('shopify_id', $sv['id'])->first();
                $data = [
                    // código de origem fica só como referência interna do fornecedor; o SKU público é desta loja
                    'supplier_sku' => $sv['sku'] ?: null,
                    'title' => $sv['title'] === 'Default Title' ? 'Padrão' : $sv['title'],
                    'option1' => $sv['option1'] === 'Default Title' ? null : $sv['option1'],
                    'option2' => $sv['option2'],
                    'option3' => $sv['option3'],
                    'price' => (int) round($sv['price'] * 100),
                    'compare_at_price' => $sv['compare_at_price'] && $sv['compare_at_price'] > $sv['price'] ? (int) round($sv['compare_at_price'] * 100) : null,
                    'weight_grams' => (int) ($sv['grams'] ?? 0),
                    'position' => $sv['position'],
                    'image_id' => isset($sv['featured_image']['id']) ? ($imageMap[$sv['featured_image']['id']] ?? null) : null,
                    'is_active' => true,
                ];
                if (! $existing || ! $this->option('keep-stock')) {
                    $data['stock'] = $stock;
                }
                $product->variants()->updateOrCreate(['shopify_id' => $sv['id']], $data);
            }

            $product->refreshPriceCache();

            return $product->id;
        });
    }

    private function normalizeOptionName(string $name): string
    {
        $n = trim($name);
        if (preg_match(self::COLOR_NAMES, $n)) {
            return 'Cor';
        }
        if (preg_match(self::SIZE_NAMES, $n)) {
            return 'Tamanho';
        }

        return rtrim($n, ': ');
    }

    /** Remove <style>/<script> que o tema injetava na descrição. */
    private function cleanHtml(?string $html): ?string
    {
        if (! $html) {
            return null;
        }
        $html = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', '', $html);

        return trim($html) ?: null;
    }
}
