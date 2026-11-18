<?php

namespace App\Console\Commands;

use App\Support\PageCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

/**
 * Instala o pacote do catálogo gerado por catalog:export (substitui o catálogo atual).
 * Pedidos, clientes, carrinhos e usuários do admin NÃO são tocados.
 *
 *   php artisan catalog:import ../catalogo-20261113-1500.zip
 */
class CatalogImport extends Command
{
    protected $signature = 'catalog:import {arquivo : Caminho do .zip gerado por catalog:export} {--force : Não pedir confirmação}';

    protected $description = 'Instala o pacote do catálogo (dados + imagens)';

    public function handle(): int
    {
        $path = $this->argument('arquivo');
        $zip = new ZipArchive;
        if (! is_file($path) || $zip->open($path) !== true) {
            $this->error("Arquivo não encontrado ou inválido: $path");

            return self::FAILURE;
        }
        $json = json_decode((string) $zip->getFromName('catalogo.json'), true);
        if (! is_array($json['tables'] ?? null)) {
            $this->error('Pacote sem catalogo.json — gere de novo com php artisan catalog:export');

            return self::FAILURE;
        }
        if (DB::table('products')->exists() && ! $this->option('force')
            && ! $this->confirm('Já existe catálogo nesta loja. Substituir produtos, banners, páginas e configurações públicas pelo do pacote?')) {
            return self::FAILURE;
        }

        // 1) Dados
        $tables = array_values(array_intersect(CatalogExport::TABLES, array_keys($json['tables'])));
        Schema::disableForeignKeyConstraints();
        try {
            foreach (array_reverse($tables) as $t) {
                if ($t === 'settings') {
                    DB::table('settings')->where('is_encrypted', false)->delete(); // mantém chaves já cadastradas
                } else {
                    DB::table($t)->delete();
                }
            }
            foreach ($tables as $t) {
                $cols = Schema::getColumnListing($t);
                $rows = array_map(fn ($r) => array_intersect_key($r, array_flip($cols)), $json['tables'][$t]);
                foreach (array_chunk($rows, 300) as $chunk) {
                    DB::table($t)->insert($chunk);
                }
                $this->line(str_pad($t, 20).count($rows));
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // 2) Arquivos (fotos, banners, logo)
        // extrai tudo de uma vez numa pasta temporária e move (muito mais rápido que arquivo por arquivo)
        $root = rtrim(\Illuminate\Support\Facades\Storage::disk('public')->path(''), '/\\');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'arquivos/') && ! str_ends_with($name, '/') && ! str_contains($name, '..')) {
                $names[] = $name;
            }
        }
        $tmp = storage_path('app/catalogo-importacao');
        \Illuminate\Support\Facades\File::deleteDirectory($tmp);
        $this->line('Extraindo '.count($names).' imagens…');
        if ($names && ! $zip->extractTo($tmp, $names)) {
            $this->error('Não foi possível extrair as imagens (espaço em disco?).');

            return self::FAILURE;
        }
        $zip->close();
        \Illuminate\Support\Facades\File::ensureDirectoryExists($root);
        \Illuminate\Support\Facades\File::copyDirectory("$tmp/arquivos", $root);
        \Illuminate\Support\Facades\File::deleteDirectory($tmp);
        $n = count($names);

        PageCache::flush();
        foreach (['layout.menus', 'redirects.map', 'settings.all', 'health.checks'] as $k) {
            Cache::forget($k);
        }
        \App\Models\Setting::flushMemo();
        $this->info("Catálogo instalado: $n arquivo(s) de imagem.");

        return self::SUCCESS;
    }
}
