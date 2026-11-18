<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use App\Support\ImageOptimizer;
use App\Support\PageCache;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

/**
 * Copia as fotos de produto que ainda estão no CDN da Shopify para a própria hospedagem,
 * já em WebP e em 5 tamanhos (200–1200px). Rodar ANTES de desligar a loja Shopify.
 *
 *   php artisan images:localize            (todas; pode rodar de novo — continua de onde parou)
 *   php artisan images:localize --limit=20 (teste)
 */
class LocalizeImages extends Command
{
    protected $signature = 'images:localize {--limit=0 : Quantas fotos processar (0 = todas)} {--concurrency=6 : Downloads simultâneos}';

    protected $description = 'Baixa as fotos de produto da Shopify e gera os tamanhos WebP locais';

    public function handle(): int
    {
        $query = ProductImage::where('path', 'like', 'http%')->orderBy('id');
        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }
        $images = $query->get();
        if ($images->isEmpty()) {
            $this->info('Todas as fotos já estão na hospedagem.');

            return self::SUCCESS;
        }

        $this->info("Copiando {$images->count()} fotos...");
        $bar = $this->output->createProgressBar($images->count());
        $ok = 0;
        $failed = [];

        foreach ($images->chunk(max(1, (int) $this->option('concurrency'))) as $chunk) {
            $chunk = $chunk->values();
            $responses = Http::pool(fn (Pool $pool) => $chunk->map(
                fn (ProductImage $img) => $pool->timeout(60)->retry(2, 1500, throw: false)->get(self::originalUrl($img->path))
            )->all());

            foreach ($chunk as $i => $img) {
                $res = $responses[$i] ?? null;
                $result = $res instanceof \Illuminate\Http\Client\Response && $res->successful()
                    ? ImageOptimizer::responsive($res->body(), "products/{$img->product_id}/{$img->id}")
                    : null;
                if ($result) {
                    $img->forceFill($result)->saveQuietly();
                    $ok++;
                } else {
                    $failed[] = $img->id;
                }
                $bar->advance();
            }
        }
        $bar->finish();
        $this->newLine(2);

        PageCache::flush();
        $this->info("Copiadas: $ok");
        if ($failed) {
            $this->warn(count($failed).' falharam (continuam no CDN da Shopify; rode o comando de novo): '.implode(', ', array_slice($failed, 0, 30)));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Remove parâmetros de tamanho para baixar a foto original */
    private static function originalUrl(string $url): string
    {
        $url = str_starts_with($url, '//') ? 'https:'.$url : $url;
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $q);
        unset($q['width'], $q['height'], $q['crop']);

        return $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '').($q ? '?'.http_build_query($q) : '');
    }
}
