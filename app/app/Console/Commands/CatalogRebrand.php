<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Support\ImageOptimizer;
use App\Support\PageCache;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Deixa o catálogo 100% desta loja quando ele veio de outra operação:
 *  - troca nomes de marca em textos (produtos, fotos, páginas, banners, menus, avaliações, configurações…)
 *  - refaz o endereço (slug) de produtos/coleções que tinham o nome antigo
 *  - fornecedor = nome desta loja; SKU público neutro (o código de origem vai para "código no fornecedor")
 *  - copia para a hospedagem as imagens das descrições e das avaliações que estavam em servidores de terceiros
 *  - remove links das descrições que levavam para outras lojas
 *
 *   php artisan catalog:rebrand --trocar="Marca Antiga=>Marca Nova" --trocar="Antiga=>Nova" [--simular]
 *   php artisan catalog:rebrand --pular-midias --apagar-codigo-fornecedor=id-da-loja-de-origem
 *
 * Pode rodar de novo: o que já está limpo fica como está.
 */
class CatalogRebrand extends Command
{
    protected $signature = 'catalog:rebrand
        {--trocar=* : Pares "antigo=>novo", aplicados em ordem (palavra inteira; mantém MAIÚSCULAS/minúsculas)}
        {--pular-midias : Não copiar imagens de descrições e avaliações agora}
        {--apagar-codigo-fornecedor=* : Apaga o "código no fornecedor" que contenha este trecho (ex.: identificador da loja de origem)}
        {--simular : Só mostra o que mudaria, sem gravar}';

    protected $description = 'Remove marcas e vínculos de outra loja do catálogo e do conteúdo';

    /** tabela => colunas de texto revisadas */
    private const TEXT = [
        'products' => ['title', 'description', 'product_type', 'tags', 'seo_title', 'seo_description'],
        'product_images' => ['alt'],
        'categories' => ['name', 'description', 'seo_title', 'seo_description'],
        'pages' => ['title', 'content', 'seo_title', 'seo_description'],
        'banners' => ['title', 'alt'],
        'home_sections' => ['title', 'settings'],
        'menu_items' => ['title'],
        'reviews' => ['title', 'body', 'reply'],
        'discounts' => ['title'],
        'shipping_methods' => ['name', 'description'],
    ];

    private array $pairs = [];

    private bool $dry = false;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('simular');
        foreach ((array) $this->option('trocar') as $p) {
            if (! str_contains($p, '=>')) {
                $this->error("Par inválido: \"$p\" (use antigo=>novo)");

                return self::FAILURE;
            }
            $this->pairs[] = array_map('trim', explode('=>', $p, 2));
        }

        $this->texts();
        $this->slugs();
        $this->vendorsAndSkus();
        if (! $this->option('pular-midias')) {
            $this->descriptionMedia();
            $this->reviewMedia();
        }
        if (! $this->dry) {
            PageCache::flush();
            cache()->forget('layout.menus');
        }
        $this->info($this->dry ? 'Simulação concluída (nada gravado).' : 'Catálogo revisado.');

        return self::SUCCESS;
    }

    /** Troca de nomes preservando o padrão de caixa (MARCA→NOVA, marca→nova, Marca→Nova) */
    public static function swap(string $text, array $pairs): string
    {
        foreach ($pairs as [$old, $new]) {
            $re = '/(?<![\p{L}\p{N}])'.preg_quote($old, '/').'(?![\p{L}\p{N}])/iu';
            $out = preg_replace_callback($re, function ($m) use ($new) {
                $t = $m[0];
                if ($t === mb_strtoupper($t) && $t !== mb_strtolower($t)) {
                    return mb_strtoupper($new);
                }

                return $t === mb_strtolower($t) ? mb_strtolower($new) : $new;
            }, $text);
            $text = $out ?? $text;
        }

        return $text;
    }

    private function texts(): void
    {
        if (! $this->pairs) {
            return;
        }
        $total = 0;
        foreach (self::TEXT as $table => $cols) {
            $n = 0;
            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $cols, &$n) {
                foreach ($rows as $row) {
                    $changes = [];
                    foreach ($cols as $c) {
                        if (is_string($row->$c ?? null) && $row->$c !== '' && ($new = self::swap($row->$c, $this->pairs)) !== $row->$c) {
                            $changes[$c] = $new;
                        }
                    }
                    if ($changes) {
                        $n++;
                        $this->dry || DB::table($table)->where('id', $row->id)->update($changes);
                    }
                }
            });
            if ($n) {
                $this->line("  $table: $n registro(s) com texto trocado");
            }
            $total += $n;
        }
        // configurações em texto (as criptografadas são chaves de integração — não mexe)
        foreach (DB::table('settings')->where('is_encrypted', false)->get() as $s) {
            if (is_string($s->value) && ($new = self::swap($s->value, $this->pairs)) !== $s->value) {
                $total++;
                $this->dry || DB::table('settings')->where('id', $s->id)->update(['value' => $new]);
                $this->line("  configuração {$s->key} atualizada");
            }
        }
        $this->info("Textos: $total registro(s) revisado(s)");
    }

    /** Endereços com o nome antigo ganham endereço novo a partir do nome novo (sem redirecionar o antigo) */
    private function slugs(): void
    {
        foreach (['products' => 'title', 'categories' => 'name'] as $table => $nameCol) {
            foreach (DB::table($table)->get(['id', 'slug', $nameCol]) as $row) {
                $hit = collect($this->pairs)->contains(fn ($p) => str_contains($row->slug, Str::slug($p[0])));
                if (! $hit) {
                    continue;
                }
                $base = Str::slug($row->$nameCol) ?: Str::slug(self::swap($row->slug, $this->pairs));
                $slug = $base; $i = 2;
                while (DB::table($table)->where('slug', $slug)->where('id', '!=', $row->id)->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $this->line("  /{$table}: {$row->slug} → {$slug}");
                $this->dry || DB::table($table)->where('id', $row->id)->update(['slug' => $slug]);
            }
        }
        // a loja nova não tem links antigos: nenhum redirecionamento deve carregar o nome antigo
        foreach ($this->pairs as [$old]) {
            $q = DB::table('redirects')->where('from_path', 'like', '%'.Str::slug($old).'%');
            if ($n = $q->count()) {
                $this->dry || $q->delete();
                $this->line("  $n redirecionamento(s) com o nome antigo removido(s)");
            }
        }
    }

    private function vendorsAndSkus(): void
    {
        $store = (string) setting('store.name');
        $n = DB::table('products')->where(fn ($q) => $q->whereNull('vendor')->orWhere('vendor', '!=', $store))->count();
        $this->dry || DB::table('products')->update(['vendor' => $store]);
        $this->line("  fornecedor exibido = \"$store\" em $n produto(s)");

        $prefix = Str::before(ProductVariant::publicSku(1), '-').'-';
        $pending = DB::table('product_variants')->where(fn ($q) => $q->whereNull('sku')->orWhere('sku', 'not like', $prefix.'%'));
        $moved = $pending->count();
        // um único UPDATE (milhares de variações): o mesmo formato de ProductVariant::publicSku()
        $this->dry || $pending->update([
            'supplier_sku' => DB::raw('COALESCE(supplier_sku, sku)'),
            'sku' => DB::raw("CONCAT(".DB::getPdo()->quote($prefix).", LPAD(id, 5, '0'))"),
        ]);
        $this->info("SKUs: $moved variação(ões) com SKU público novo ({$prefix}…); o código antigo ficou em \"código no fornecedor\"");

        // códigos de fornecedor que só identificam a loja de origem (não servem para comprar no fornecedor)
        foreach (array_filter(array_map('trim', (array) $this->option('apagar-codigo-fornecedor'))) as $part) {
            $q = DB::table('product_variants')->where('supplier_sku', 'like', '%'.$part.'%');
            $n = $q->count();
            $this->dry || $q->update(['supplier_sku' => null]);
            $this->info("Código no fornecedor: $n variação(ões) com \"$part\" apagado(s)");
        }
    }

    /** Imagens das descrições hospedadas em lojas de terceiros (Shopify) → hospedagem própria; links externos → removidos */
    private function descriptionMedia(): void
    {
        $products = Product::withTrashed()->where(fn ($q) => $q->where('description', 'like', '%cdn.shopify.com%')
            ->orWhere('description', 'like', '%/cdn/shop/%')->orWhere('description', 'like', '%<a %'))->get(['id', 'description']);
        $copied = 0; $unlinked = 0; $updates = [];
        foreach ($products as $p) {
            $html = (string) $p->description;
            // 1) links para fora da loja: mantém o conteúdo, tira o link
            $html = preg_replace_callback('#<a\b[^>]*href=["\']((?:https?:)?//[^"\']+)["\'][^>]*>(.*?)</a>#is', function ($m) use (&$unlinked) {
                $unlinked++;

                return $m[2];
            }, $html) ?? $html;
            // 2) imagens e vídeos do CDN da Shopify
            $cdn = '((?:https?:)?//(?:cdn\.shopify\.com|[^"\']+/cdn/shop/)[^"\']+)';
            preg_match_all('#<img\b[^>]*\bsrc=["\']'.$cdn.'["\']#i', $html, $m);
            preg_match_all('#<(?:source|video)\b[^>]*\bsrc=["\']'.$cdn.'["\']#i', $html, $mv);
            $dest = fn ($url) => "products/{$p->id}/descricao-".substr(md5($url), 0, 10);
            $gone = [];
            $map = $this->download(array_values(array_unique($m[1])), $dest, 1200, false, $gone)
                + $this->download(array_values(array_unique($mv[1])), $dest, 0, true, $gone);
            foreach ($map as $url => $local) {
                $html = str_replace($url, $local, $html);
                $copied++;
            }
            // imagem que já não existe na origem (404): aparecia quebrada — sai da descrição
            foreach ($gone as $url) {
                $html = preg_replace('#<img\b[^>]*\bsrc=["\']'.preg_quote($url, '#').'["\'][^>]*>#i', '', $html) ?? $html;
                $removed = ($removed ?? 0) + 1;
            }
            // tira srcset/data-src que ainda apontem para o CDN antigo
            $html = preg_replace('#\s(?:srcset|data-srcset|data-src)=["\'][^"\']*(?:cdn\.shopify\.com|/cdn/shop/)[^"\']*["\']#i', '', $html) ?? $html;
            if ($html !== $p->description) {
                $updates[$p->id] = $html;
            }
        }
        // grava tudo numa transação só (muito mais rápido que uma gravação por produto)
        $this->dry || DB::transaction(function () use ($updates) {
            foreach ($updates as $id => $html) {
                DB::table('products')->where('id', $id)->update(['description' => $html]);
            }
        });
        $left = Product::withTrashed()->where('description', 'like', '%cdn.shopify.com%')->count();
        $this->info("Descrições: $copied imagem(ns)/vídeo(s) copiado(s), $unlinked link(s) externo(s) removido(s)"
            .(($removed ?? 0) ? ", {$removed} imagem(ns) quebrada(s) retirada(s)" : '')
            .($left ? " — $left produto(s) ainda com mídia externa (rode de novo)" : ''));
    }

    /** Fotos das avaliações hospedadas no app de avaliações da outra loja → hospedagem própria */
    private function reviewMedia(): void
    {
        $copied = 0; $failed = 0;
        Review::whereNotNull('media')->where('media', 'like', '%http%')->orderBy('id')->chunkById(100, function ($reviews) use (&$copied, &$failed) {
            $urls = [];
            foreach ($reviews as $r) {
                foreach ((array) $r->media as $i => $it) {
                    if (str_starts_with((string) ($it['url'] ?? ''), 'http')) {
                        $urls[$it['url']] = "reviews/{$r->id}/".($i + 1);
                    }
                }
            }
            $map = $this->download(array_keys($urls), fn ($url) => $urls[$url], 1200);
            $save = [];
            foreach ($reviews as $r) {
                $media = (array) $r->media; $changed = false;
                foreach ($media as $i => $it) {
                    $u = $it['url'] ?? '';
                    if (isset($map[$u])) { $media[$i]['url'] = $map[$u]; $changed = true; $copied++; }
                    elseif (str_starts_with($u, 'http')) { $failed++; }
                }
                if ($changed) {
                    $save[] = [$r, array_values($media)];
                }
            }
            $this->dry || DB::transaction(function () use ($save) {
                foreach ($save as [$r, $media]) {
                    $r->forceFill(['media' => $media])->saveQuietly();
                }
            });
        });
        $this->info("Avaliações: $copied foto(s) copiada(s)".($failed ? ", $failed falharam (rode de novo)" : ''));
    }

    /**
     * Baixa em paralelo e grava em WebP. Devolve [url original => caminho público "/storage/..."].
     *
     * @param  callable(string): string  $dest  caminho sem extensão no disco public
     * @param  bool  $raw  grava como veio (vídeo), sem converter
     * @param  array  $gone  recebe as URLs que não existem mais na origem (404/410)
     */
    private function download(array $urls, callable $dest, int $maxWidth, bool $raw = false, array &$gone = []): array
    {
        $out = [];
        foreach (array_chunk($urls, 8) as $chunk) {
            if ($this->dry) {
                foreach ($chunk as $u) { $out[$u] = '/storage/'.$dest($u).'.webp'; }

                continue;
            }
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($u) => $pool->timeout(60)->retry(2, 1000, throw: false)->get(str_starts_with($u, '//') ? 'https:'.$u : $u),
                $chunk
            ));
            foreach ($chunk as $i => $u) {
                $res = $responses[$i] ?? null;
                if ($res instanceof \Illuminate\Http\Client\Response && in_array($res->status(), [404, 410], true)) {
                    $gone[] = $u;                          // não existe mais na origem

                    continue;
                }
                if (! $res instanceof \Illuminate\Http\Client\Response || ! $res->successful()) {
                    continue;
                }
                $path = $dest($u);
                $ext = strtolower(pathinfo(parse_url($u, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
                if ($raw) {                                 // vídeo: guarda como veio
                    $file = $path.'.'.($ext ?: 'mp4');
                    Storage::disk('public')->put($file, $res->body());
                    $out[$u] = '/storage/'.$file;

                    continue;
                }
                $orig = $path.'.'.(in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $ext : 'jpg');
                Storage::disk('public')->put($orig, $res->body());
                if ($ext === 'gif') {                       // GIF animado: mantém como está
                    $out[$u] = '/storage/'.$orig;

                    continue;
                }
                $webp = ImageOptimizer::toWebp($orig, $maxWidth);
                if ($webp !== $orig) {
                    Storage::disk('public')->delete($orig);
                }
                $out[$u] = '/storage/'.$webp;
            }
        }

        return $out;
    }
}
