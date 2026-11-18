<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Pacote do catálogo para instalar a loja em produção sem buscar nada em outra loja:
 * produtos, variações, fotos, coleções, avaliações, banners, home, menus, páginas, frete, cupons
 * e configurações públicas (chaves de integração NÃO entram) + todos os arquivos de imagem.
 *
 *   php artisan catalog:export            → ../releases/catalogo-AAAAMMDD-HHMM.zip
 *   (no servidor) php artisan catalog:import caminho/do/catalogo.zip
 */
class CatalogExport extends Command
{
    protected $signature = 'catalog:export {--out= : Pasta de saída (padrão: ../releases)} {--manter-ids-de-origem : Mantém IDs da plataforma de origem (Shopify/app de avaliações)}';

    protected $description = 'Gera o pacote do catálogo (dados + imagens) para instalar em produção';

    /** ordem importa: pais antes dos filhos */
    public const TABLES = [
        'brands', 'categories', 'products', 'product_options', 'product_images', 'product_variants', 'category_product',
        'reviews', 'menus', 'menu_items', 'banners', 'home_sections', 'pages', 'shipping_methods',
        'discounts', 'discount_targets', 'redirects', 'settings',
    ];

    /** pastas do disco public que vão no pacote */
    public const FOLDERS = ['products', 'reviews', 'banners', 'brand', 'brands', 'marca', 'collections', 'pages'];

    public function handle(): int
    {
        $outDir = $this->option('out') ?: dirname(base_path()).DIRECTORY_SEPARATOR.'releases';
        @mkdir($outDir, 0775, true);
        $file = $outDir.DIRECTORY_SEPARATOR.'catalogo-'.now()->format('Ymd-Hi').'.zip';

        $data = [];
        foreach (self::TABLES as $t) {
            $q = DB::table($t);
            if ($t === 'settings') {
                $q->where('is_encrypted', false);           // chaves/tokens ficam fora
            }
            $rows = $q->get()->map(fn ($r) => (array) $r)->all();
            if (! $this->option('manter-ids-de-origem')) {
                $rows = array_map(fn ($r) => $this->stripOrigin($t, $r), $rows);
            }
            $data[$t] = $rows;
            $this->line(str_pad($t, 20).count($rows));
        }

        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Não foi possível criar $file");

            return self::FAILURE;
        }
        $zip->addFromString('catalogo.json', json_encode(['version' => 1, 'created_at' => now()->toIso8601String(), 'tables' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $files = 0;
        $root = rtrim(\Illuminate\Support\Facades\Storage::disk('public')->path(''), '/\\');
        foreach (self::FOLDERS as $folder) {
            if (! is_dir("$root/$folder")) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$folder", RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
                $zip->addFile($f->getPathname(), 'arquivos/'.$rel);
                $zip->setCompressionName('arquivos/'.$rel, ZipArchive::CM_STORE); // imagens já são comprimidas
                $files++;
            }
        }
        $zip->close();
        $this->info("Pacote do catálogo: $file (".round(filesize($file) / 1024 / 1024, 1)." MB, $files arquivos)");

        return self::SUCCESS;
    }

    /** IDs da plataforma/app de origem não vão para a loja nova (nada que ligue os catálogos) */
    private function stripOrigin(string $table, array $row): array
    {
        foreach (['shopify_id'] as $col) {
            if (array_key_exists($col, $row)) {
                $row[$col] = null;
            }
        }
        if ($table === 'reviews' && array_key_exists('external_id', $row)) {
            $row['external_id'] = null;
        }

        return $row;
    }
}
