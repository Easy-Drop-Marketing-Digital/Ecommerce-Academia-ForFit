<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Importa as avaliações PUBLICADAS do app BK Reviews (loja Shopify antiga), pela mesma API pública que o widget usa.
 * Idempotente: reimportar atualiza em vez de duplicar (external_id = "bk:<id>").
 *
 *   php artisan reviews:import-bk loja-de-origem.myshopify.com
 */
class ImportBkReviews extends Command
{
    protected $signature = 'reviews:import-bk {shop : domínio myshopify da loja antiga} {--product= : importar só um produto (id desta loja)}';

    protected $description = 'Importa avaliações publicadas do app BK Reviews da loja Shopify antiga';

    private const BASE = 'https://bk-reviews-shopify-backend-production.up.railway.app/reviews/';

    public function handle(): int
    {
        $shop = $this->argument('shop');
        $products = Product::withTrashed()->whereNotNull('shopify_id')
            ->when($this->option('product'), fn ($q, $id) => $q->whereKey($id))
            ->get(['id', 'shopify_id', 'title'])->keyBy('shopify_id');

        // 1) Quais produtos têm avaliações (consulta em lotes de 40)
        $withReviews = [];
        foreach ($products->keys()->chunk(40) as $chunk) {
            $res = $this->get(self::BASE.$shop.'/metadata', ['reviews_per_rating' => 'true', 'filter' => 'published', 'product_ids' => $chunk->implode(',')]);
            foreach ($res['statistics'] ?? [] as $s) {
                if (($s['qty_general_rating'] ?? 0) > 0) {
                    $withReviews[(string) $s['_id']] = (int) $s['qty_general_rating'];
                }
            }
        }
        $this->info(count($withReviews).' produtos com avaliações · '.array_sum($withReviews).' avaliações no app');

        // 2) Avaliações de cada produto (paginação por cursor last_id)
        $bar = $this->output->createProgressBar(array_sum($withReviews));
        $total = 0;
        foreach ($withReviews as $shopifyId => $expected) {
            $product = $products->get($shopifyId);
            if (! $product) {
                continue;
            }
            $cursor = '';
            for ($page = 0; $page < 1000; $page++) {
                $res = $this->get(self::BASE.$shop.'/'.$shopifyId, ['last_id' => $cursor, 'filter' => 'published']);
                $reviews = $res['reviews'] ?? [];
                if (! $reviews) {
                    break;
                }
                $rows = [];
                foreach ($reviews as $r) {
                    if (empty($r['_id']) || empty($r['rating'])) {
                        continue;
                    }
                    $created = self::objectIdTime($r['_id']);
                    $rows[] = [
                        'external_id' => 'bk:'.$r['_id'],
                        'product_id' => $product->id,
                        'author' => mb_substr(trim((string) ($r['author'] ?? '')) ?: 'Cliente', 0, 120),
                        'rating' => max(1, min(5, (int) $r['rating'])),
                        'body' => trim((string) ($r['comment'] ?? '')) ?: null,
                        'media' => ($m = self::media($r)) ? json_encode($m, JSON_UNESCAPED_SLASHES) : null,
                        'status' => ($r['approved'] ?? true) ? 'approved' : 'hidden',
                        'source' => 'import',
                        'verified' => false,
                        'created_at' => $created,
                        'updated_at' => now(),
                    ];
                }
                // upsert direto (sem eventos) — a nota média é recalculada uma vez por produto no fim
                Review::upsert($rows, ['external_id'], ['product_id', 'author', 'rating', 'body', 'media', 'updated_at']);
                $total += count($rows);
                $bar->advance(count($rows));

                $next = $res['last_review_id'] ?? null;
                if (! $next || $next === $cursor) {
                    break;
                }
                $cursor = $next;
            }
            Review::refreshProduct($product->id);
        }
        $bar->finish();
        $this->newLine();
        $this->info("$total avaliações importadas/atualizadas.");

        return self::SUCCESS;
    }

    private function get(string $url, array $query): array
    {
        return Http::retry(3, 1000, throw: false)->timeout(30)->acceptJson()->get($url, $query)->json() ?? [];
    }

    /** Os 4 primeiros bytes de um ObjectId do MongoDB são o timestamp de criação */
    private static function objectIdTime(string $id): string
    {
        $ts = ctype_xdigit(substr($id, 0, 8)) ? hexdec(substr($id, 0, 8)) : time();

        return date('Y-m-d H:i:s', $ts > 1_500_000_000 && $ts <= time() ? $ts : time());
    }

    /** Fotos/vídeos podem vir como string (URL) ou objeto ({url|src|link}) */
    private static function media(array $r): array
    {
        $out = [];
        foreach (['images' => 'image', 'videos' => 'video'] as $key => $type) {
            foreach ((array) ($r[$key] ?? []) as $m) {
                $url = is_string($m) ? $m : ($m['url'] ?? $m['src'] ?? $m['link'] ?? $m['secure_url'] ?? null);
                if (is_string($url) && str_starts_with($url, 'http')) {
                    $out[] = ['type' => $type, 'url' => $url];
                }
            }
        }

        return $out;
    }
}
