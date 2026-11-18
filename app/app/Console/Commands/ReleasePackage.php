<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Gera o .zip para subir na Hostinger (Gerenciador de Arquivos ou SSH).
 * Leva o código + CSS/JS já compilados; NÃO leva .env, vendor (o servidor instala com composer),
 * node_modules, testes, logs nem fotos (o servidor gera com images:localize).
 *
 *   npm run build && php artisan release:package
 */
class ReleasePackage extends Command
{
    protected $signature = 'release:package {--out= : Pasta de saída (padrão: ../releases)}';

    protected $description = 'Empacota a loja para enviar à hospedagem';

    private const INCLUDE = ['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'storage',
        'artisan', 'composer.json', 'composer.lock', 'deploy.sh', '.env.production.example'];

    public function handle(): int
    {
        $manifest = public_path('build/manifest.json');
        if (! is_file($manifest)) {
            $this->error('CSS/JS não compilados. Rode antes: npm run build');

            return self::FAILURE;
        }
        $newest = $this->newestMtime(resource_path());
        if ($newest > filemtime($manifest)) {
            $this->warn('Há arquivos em resources/ mais novos que o último "npm run build". Rode o build de novo se mudou CSS/JS/views do admin.');
        }

        $outDir = $this->option('out') ?: dirname(base_path()).DIRECTORY_SEPARATOR.'releases';
        @mkdir($outDir, 0775, true);
        $file = $outDir.DIRECTORY_SEPARATOR.'loja-'.now()->format('Ymd-Hi').'.zip';

        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Não foi possível criar $file");

            return self::FAILURE;
        }
        $count = 0;
        foreach (self::INCLUDE as $item) {
            $path = base_path($item);
            if (is_file($path)) {
                $zip->addFile($path, $item);
                $count++;

                continue;
            }
            if (! is_dir($path)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $f) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(base_path()) + 1));
                if ($this->excluded($rel)) {
                    continue;
                }
                if ($f->isDir()) {
                    $zip->addEmptyDir($rel);
                } elseif (! $f->isLink()) {
                    $zip->addFile($f->getPathname(), $rel);
                    $count++;
                }
            }
        }
        $zip->close();

        $this->info("Pacote criado: $file (".round(filesize($file) / 1024 / 1024, 1)." MB, $count arquivos)");
        $this->line('Na hospedagem: extraia por cima da pasta da loja e rode ./deploy.sh (veja DEPLOY.md).');

        return self::SUCCESS;
    }

    private function excluded(string $rel): bool
    {
        // conteúdo gerado em produção fica de fora (só as pastas vazias vão)
        if (preg_match('#^storage/(app|framework|logs)/.+#', $rel) && ! str_ends_with($rel, '.gitignore')
            && ! in_array($rel, ['storage/app/public', 'storage/app/private', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views'], true)) {
            return true;
        }

        return str_starts_with($rel, 'public/storage')
            || $rel === 'public/hot'
            || (str_starts_with($rel, 'bootstrap/cache/') && str_ends_with($rel, '.php'));
    }

    private function newestMtime(string $dir): int
    {
        $max = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            $max = max($max, $f->getMTime());
        }

        return $max;
    }
}
