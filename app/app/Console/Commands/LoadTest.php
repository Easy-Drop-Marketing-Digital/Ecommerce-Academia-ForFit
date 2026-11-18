<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

/**
 * Teste de carga simples da vitrine (antes da Black Friday / antes de apontar o domínio):
 * dispara visitas simultâneas em home, coleções e produtos e mostra visitas/segundo e tempo de resposta.
 * Só baixa o HTML (não roda JavaScript): não gera eventos no Meta nem visitas no relatório.
 *
 *   php artisan store:loadtest https://lojaforfit.com.br --c=30 --seconds=60
 */
class LoadTest extends Command
{
    protected $signature = 'store:loadtest {url : Endereço da loja (ex.: https://lojaforfit.com.br)} {--c=20 : Visitas simultâneas} {--seconds=30 : Duração}';

    protected $description = 'Mede quantas visitas por segundo a vitrine aguenta';

    public function handle(): int
    {
        $base = rtrim($this->argument('url'), '/');
        $paths = $this->paths();
        $c = max(1, (int) $this->option('c'));
        $until = microtime(true) + max(5, (int) $this->option('seconds'));

        $this->info("Testando $base com $c visitas simultâneas por {$this->option('seconds')}s em ".count($paths).' páginas...');
        $times = [];
        $errors = [];
        $cacheHits = 0;
        $start = microtime(true);
        $i = 0;

        while (microtime(true) < $until) {
            $batch = [];
            for ($k = 0; $k < $c; $k++) {
                $batch[] = $paths[$i++ % count($paths)];
            }
            $t0 = microtime(true);
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($p) => $pool->withHeaders(['User-Agent' => 'StoreLoadTest/1.0'])->timeout(30)->get($base.$p),
                $batch
            ));
            foreach ($responses as $n => $res) {
                if ($res instanceof \Illuminate\Http\Client\Response) {
                    $times[] = $res->handlerStats()['total_time'] ?? (microtime(true) - $t0);
                    if (! $res->successful()) {
                        $errors[] = $res->status().' '.$batch[$n];
                    }
                    $cacheHits += $res->header('X-Page-Cache') === 'HIT' ? 1 : 0;
                } else {
                    $errors[] = 'falha de conexão '.$batch[$n];
                }
            }
        }

        $elapsed = microtime(true) - $start;
        sort($times);
        $pct = fn ($p) => $times ? round($times[(int) floor((count($times) - 1) * $p)] * 1000) : 0;
        $total = count($times) + count(array_filter($errors, fn ($e) => str_starts_with($e, 'falha')));

        $this->newLine();
        $this->table(['Visitas', 'Por segundo', 'Mediana', '95% abaixo de', 'Mais lenta', 'Erros', 'Do cache'], [[
            $total, round($total / $elapsed, 1), $pct(0.5).' ms', $pct(0.95).' ms', $pct(1).' ms',
            count($errors), $times ? round($cacheHits / count($times) * 100).'%' : '—',
        ]]);
        if ($errors) {
            $this->warn('Exemplos de erro: '.implode(' | ', array_slice(array_unique($errors), 0, 5)));
        }
        $this->line('Referência: mediana abaixo de 300 ms e 0 erros = folga. Erros 503/508 = limite de processos da hospedagem.');

        return $errors ? self::FAILURE : self::SUCCESS;
    }

    /** Páginas mais visitadas: home, 3 coleções e 6 produtos mais vendidos */
    private function paths(): array
    {
        $paths = ['/'];
        try {
            $paths = array_merge($paths,
                Category::where('is_active', true)->orderBy('position')->limit(3)->pluck('slug')->map(fn ($s) => "/collections/$s")->all(),
                Product::active()->orderByDesc('sales_count')->limit(6)->pluck('slug')->map(fn ($s) => "/products/$s")->all(),
            );
        } catch (\Throwable) {
            $paths[] = '/collections/all';
        }

        return $paths;
    }
}
