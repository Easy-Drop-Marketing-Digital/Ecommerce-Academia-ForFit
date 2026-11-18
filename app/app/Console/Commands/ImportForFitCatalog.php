<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa o catálogo da ForFit montado a partir dos sites oficiais das marcas (pesquisa/montar-catalogo.js):
 * 10 produtos de cada marca por segmento, na ordem de popularidade, com sabores, fotos e ficha técnica.
 *
 *   php artisan forfit:catalogo database/catalogo/catalogo-forfit.json --stock=20 --download-images
 *
 * Idempotente: atualiza pelo endereço de origem (source_url). Estoque só é definido na criação da variação
 * (ou sempre, com --reset-stock); preço vem do site da marca e é ajustado depois no admin.
 */
class ImportForFitCatalog extends Command
{
    protected $signature = 'forfit:catalogo {arquivo=database/catalogo/catalogo-forfit.json} {--stock=20 : Estoque inicial de cada variação nova} {--reset-stock : Sobrescreve o estoque das variações existentes} {--download-images : Copia as fotos para a hospedagem em WebP}';

    protected $description = 'Importa o catálogo ForFit (Growth + Max Titanium) por segmento';

    private const BRANDS = [
        'Growth Supplements' => ['slug' => 'growth-supplements', 'color' => '#0b74c4', 'position' => 0],
        'Max Titanium' => ['slug' => 'max-titanium', 'color' => '#111111', 'position' => 1],
    ];

    private const GOOGLE = 'Health & Beauty > Health Care > Fitness & Nutrition > Vitamins & Supplements';

    public function handle(): int
    {
        $path = base_path($this->argument('arquivo'));
        if (! is_file($path)) {
            $path = $this->argument('arquivo');
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (! is_array($data['segmentos'] ?? null)) {
            $this->error("Arquivo inválido: {$this->argument('arquivo')}");

            return self::FAILURE;
        }

        $brands = collect(self::BRANDS)->map(fn ($b, $name) => Brand::updateOrCreate(['slug' => $b['slug']], [
            'name' => $name, 'color' => $b['color'], 'position' => $b['position'], 'is_active' => true,
            'seo_title' => "$name original com revenda autorizada",
        ]));

        $kits = Category::updateOrCreate(['slug' => 'kits'], ['name' => 'Kits e Combos', 'position' => 90, 'is_active' => true]);
        $best = Category::updateOrCreate(['slug' => 'mais-vendidos'], ['name' => 'Mais vendidos', 'position' => 0, 'is_active' => true]);
        $kitSync = []; $bestSync = []; $total = 0;

        foreach ($data['segmentos'] as $i => $seg) {
            $category = Category::updateOrCreate(['slug' => Str::slug($seg['name'])], [
                'name' => $seg['name'], 'position' => $i + 1, 'is_active' => true, 'sort_order' => 'manual',
                'seo_title' => $seg['name'].' Growth e Max Titanium',
            ]);

            // vitrine do segmento alterna as marcas: Growth 1, Max 1, Growth 2, Max 2...
            $rows = [];
            foreach (['growth', 'max'] as $b => $key) {
                foreach ($seg[$key] ?? [] as $p) {
                    $rows[] = ['order' => ($p['rank'] * 2) + $b, 'data' => $p];
                }
            }
            usort($rows, fn ($a, $b) => $a['order'] <=> $b['order']);

            $sync = [];
            foreach ($rows as $pos => $row) {
                $p = $row['data'];
                $product = $this->importProduct($p, $brands[$p['brand']], $seg['name']);
                $sync[$product->id] = ['position' => $pos];
                if (preg_match('/^(kit|pack|combo)\b/i', $product->title)) {
                    $kitSync[$product->id] = ['position' => count($kitSync)];
                }
                if ($p['rank'] <= 2 && ! str_contains($p['origem'], 'kit')) {
                    $bestSync[$product->id] = ['position' => count($bestSync)];
                }
                $total++;
            }
            $category->products()->sync($sync);
            $this->line(sprintf('%-30s %d produtos', $seg['name'], count($sync)));
        }

        $kits->products()->sync($kitSync);
        $best->products()->sync($bestSync);
        $this->info("$total produtos importados (".count($kitSync).' kits, '.count($bestSync).' em "Mais vendidos").');

        if ($this->option('download-images')) {
            $this->call('images:localize');
        }
        \App\Support\PageCache::flush();

        return self::SUCCESS;
    }

    private function importProduct(array $p, Brand $brand, string $segment): Product
    {
        return DB::transaction(function () use ($p, $brand, $segment) {
            $title = $this->cleanTitle($p['title']);
            $product = Product::withTrashed()->firstOrNew(['source_url' => $p['source_url']]);
            if (! $product->exists) {
                $product->slug = $this->uniqueSlug($title, $brand);
            }
            $product->fill([
                'title' => $title,
                'brand_id' => $brand->id,
                'vendor' => $brand->name,
                'product_type' => $segment,
                'description' => $this->description($p),
                'specs' => $this->specs($p),
                'tags' => array_values(array_unique([$segment, $brand->name, ...array_map('strval', Arr::get($p, 'options.0.values', []))])),
                'status' => 'active',
                'google_category' => self::GOOGLE,
                'seo_title' => Str::limit("$title {$brand->name}", 60, ''), // o layout acrescenta "| ForFit"
                'seo_description' => Str::limit("Compre $title da {$brand->name} com revenda autorizada, envio rápido e parcelamento sem juros.", 155, ''),
                'published_at' => $product->published_at ?? now(),
                'deleted_at' => null,
            ])->save();

            // Fotos: galeria do produto + foto de cada sabor (sem repetir)
            $urls = collect($p['images'] ?? [])->merge(collect($p['variants'] ?? [])->pluck('images')->flatten())->filter()->unique()->values();
            $imageIds = [];
            foreach ($urls as $pos => $url) {
                $img = ProductImage::firstOrNew(['product_id' => $product->id, 'source_url' => $url]);
                if (! $img->exists) {
                    $img->path = $url; // images:localize troca pelo arquivo local
                }
                $img->alt = $title.' '.$brand->name;
                $img->position = $pos;
                $img->save();
                $imageIds[$url] = $img->id;
            }
            ProductImage::where('product_id', $product->id)->whereNotIn('id', $imageIds ?: [0])->get()->each->delete();

            // Opção "Sabor"
            $product->options()->delete();
            $values = Arr::get($p, 'options.0.values', []);
            if (count($values) > 1) {
                $product->options()->create([
                    'name' => 'Sabor', 'position' => 1, 'values' => array_values($values),
                    'display_type' => count($values) > 6 ? 'select' : 'button',
                ]);
            }

            // Variações (estoque novo = --stock; existente fica como está, salvo --reset-stock)
            $keep = [];
            foreach ($p['variants'] ?? [] as $pos => $v) {
                $variant = $product->variants()->firstOrNew(['supplier_sku' => $p['brand'] === 'Max Titanium' ? 'MAX-'.$v['source_sku'] : 'GRW-'.$v['source_sku']]);
                $price = (int) ($v['price'] ?? 0) ?: (int) $p['price'];
                $compare = (int) ($v['compare_at'] ?? 0) ?: (int) ($p['compare_at'] ?? 0);
                $variant->fill([
                    'title' => count($values) > 1 ? $v['title'] : 'Padrão',
                    'option1' => count($values) > 1 ? $v['option1'] : null,
                    'price' => $price,
                    'compare_at_price' => $compare > $price ? $compare : null,
                    'weight_grams' => (int) ($p['weight_grams'] ?? 500),
                    'position' => $pos,
                    'is_active' => true,
                    'image_id' => isset($v['images'][0]) ? ($imageIds[$v['images'][0]] ?? null) : null,
                ]);
                if (! $variant->exists || $this->option('reset-stock')) {
                    $variant->stock = (int) $this->option('stock');
                }
                $variant->save();
                $keep[] = $variant->id;
            }
            // variação que saiu do site da marca: apaga se nunca foi vendida; se já tem pedido, só desativa (histórico)
            $gone = $product->variants()->whereNotIn('id', $keep ?: [0]);
            (clone $gone)->whereExists(fn ($q) => $q->from('order_items')->whereColumn('order_items.variant_id', 'product_variants.id'))->update(['is_active' => false]);
            (clone $gone)->whereNotExists(fn ($q) => $q->from('order_items')->whereColumn('order_items.variant_id', 'product_variants.id'))->get()->each->delete();
            $product->refreshPriceCache();

            return $product;
        });
    }

    /** "Vitamina C – 500 mg (60 cápsulas) – Max Titanium" → "Vitamina C 500 mg (60 cápsulas)" */
    private function cleanTitle(string $t): string
    {
        $t = preg_replace('/\s*[–—|-]\s*(Max Titanium|Growth Supplements)\b.*$/iu', '', $t);
        $t = preg_replace('/\s+–\s+/u', ' ', $t);
        $t = preg_replace_callback('/\b([A-ZÁÉÍÓÚÇÃÕ]{4,})\b/u', fn ($m) => in_array($m[1], ['BCAA', 'ZMA', 'HMB', 'WPC', 'MCT', 'DHA', 'UHT']) ? $m[1] : mb_convert_case($m[1], MB_CASE_TITLE), $t);

        return trim(preg_replace('/\s{2,}/', ' ', $t));
    }

    private function uniqueSlug(string $title, Brand $brand): string
    {
        $base = Str::slug($title.' '.($brand->slug === 'max-titanium' ? 'max titanium' : 'growth'));
        $slug = $base; $n = 2;
        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    private function description(array $p): string
    {
        $s = $this->specs($p);
        $html = $s['sobre'] ?? '';
        if (! $html && ! empty($s['destaques'])) {
            $html = '<ul>'.collect($s['destaques'])->map(fn ($d) => '<li>'.e($d).'</li>')->implode('').'</ul>';
        }

        return $html ?: '<p>'.e($p['title']).' — produto original com revenda autorizada.</p>';
    }

    /**
     * Ficha técnica no mesmo formato para as duas marcas:
     * sobre (HTML), destaques[], beneficios[], modo_de_uso, ingredientes, alergicos, advertencias, tabela{}, faq[]
     */
    private function specs(array $p): array
    {
        $clean = fn (?string $h) => trim((string) preg_replace('#<(script|style|iframe)\b[^>]*>.*?</\1>#is', '', (string) $h)) ?: null;
        $text = fn (?string $h) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $h)))) ?: null;
        $list = fn (?string $h) => collect(preg_split('#</li>|<br\s*/?>|\n#i', (string) $h))->map($text)->filter()->values()->all();

        if ($p['brand'] === 'Max Titanium') {
            $sp = $p['specs'] ?? [];

            return array_filter([
                'sobre' => $clean($sp['Sobre o Produto'] ?? null) ?: $clean($p['description_html'] ?? null),
                'tamanho' => $text($sp['Tamanho'] ?? null),
                'destaques' => $list($sp['Destaques do Produto'] ?? ($sp['Destaques'] ?? null)),
                'beneficios' => $list($sp['Benefícios'] ?? null),
                'modo_de_uso' => $text($sp['Recomendação de uso'] ?? null),
                'preparo' => $text($sp['Modo de Preparo'] ?? null),
                'ingredientes' => $text($sp['Ingredientes'] ?? null),
                'alergicos' => $text($sp['Alergênicos'] ?? null),
                'advertencias' => $text($sp['Advertências'] ?? null),
            ]);
        }

        // Growth: destaques do topo da página, características, bloco de informações do rótulo, tabela e dúvidas
        $info = collect($p['info'] ?? []);
        $pickInfo = fn (string $re) => $text(preg_replace('#^<b>[^<]*</b>\s*#i', '', (string) $info->first(fn ($l) => preg_match($re, $l))));
        $carac = collect($p['caracteristicas'] ?? []);

        return array_filter([
            'destaques' => collect($p['highlights'] ?? [])->map(fn ($h) => trim($h['titulo'].($h['texto'] ? ': '.$h['texto'] : '')))->filter()->values()->all()
                ?: collect($carac->firstWhere('grupo', 'Benefícios')['itens'] ?? [])->all(),
            'beneficios' => collect($carac->firstWhere('grupo', 'Benefícios')['itens'] ?? [])->all(),
            'modo_de_uso' => $pickInfo('/RECOMENDA/i') ?: $text($p['usage'] ?? null),
            'preparo' => $pickInfo('/PREPARO/i'),
            'ingredientes' => $pickInfo('/INGREDIENTES/i') ?: $text(collect($carac->firstWhere('grupo', 'Lista de Ingredientes')['itens'] ?? [])->implode(' ')),
            'conservacao' => $pickInfo('/CONSERVA/i'),
            'advertencias' => $info->filter(fn ($l) => ! preg_match('#^<b>#i', trim($l)))->map($text)->filter()->implode(' ') ?: null,
            'tabela' => ! empty($p['nutrition']) ? [
                'porcao' => $p['nutrition']['porcao'],
                'porcoes' => $p['nutrition']['porcoes'],
                'linhas' => $p['nutrition']['linhas'],
                'rodape' => $text($p['nutrition']['rodape'] ?? null) ?: $text($p['nutrition']['texto_cima'] ?? null),
            ] : null,
            'faq' => $p['faq'] ?? [],
        ]);
    }
}
