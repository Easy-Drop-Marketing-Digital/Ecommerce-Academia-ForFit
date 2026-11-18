<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cache de página da vitrine (home, coleções, produtos, páginas) para aguentar pico de Black Friday
 * na hospedagem compartilhada: a página pronta vai para um arquivo e as próximas visitas pulam
 * banco + renderização. O que é de cada visitante NÃO fica no cache — é preenchido a cada acesso:
 *   - token CSRF, carrinho (window.__CART__) e dados de correspondência dos pixels (window.SD_TRACK).
 *
 * Limpa sozinho quando algo visível muda no admin (produto, preço, banner, menu, avaliação, configuração)
 * ou quando uma variação esgota/volta. Validade máxima de 5 min (banners/cupons agendados entram no horário).
 */
class PageCache
{
    public const TTL = 300;

    private const CSRF = '%%SD_CSRF%%';

    /** Parâmetros que mudam o conteúdo da página (entram na chave) */
    private const VARY = ['page', 'sort_by', 'availability', 'price_min', 'price_max', 'variant'];

    /** Parâmetros de campanha: não mudam a página (já foram capturados pelo CaptureAttribution) */
    private const IGNORE = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'fbclid', 'gclid', 'gbraid', 'wbraid', 'gad_source', 'gad_campaignid', 'ttclid', 'msclkid', 'srsltid',
        'igshid', '_gl', '_ga', 'sck', 'src', 'xcod', 'ref', 'mc_cid', 'mc_eid', 'campaign_id', 'adset_id', 'ad_id',
    ];

    /** Trechos por visitante, marcados no layout */
    private const HOLES = ['cart', 'track'];

    public static function enabled(): bool
    {
        return (bool) config('store.page_cache');
    }

    public static function dir(): string
    {
        return storage_path('framework/page-cache');
    }

    /** Chave do arquivo, ou null quando esta requisição não deve usar cache */
    public static function key(Request $request): ?string
    {
        if (! self::enabled() || ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return null;
        }
        if (auth('customer')->check() || $request->session()->has('errors') || $request->session()->has('status') || $request->session()->has('toast')) {
            return null;
        }
        $vary = [];
        foreach ($request->query() as $k => $v) {
            if (in_array($k, self::IGNORE, true)) {
                continue;
            }
            if (! in_array($k, self::VARY, true) || ! is_scalar($v)) {
                return null; // parâmetro desconhecido: não arrisca
            }
            $vary[$k] = (string) $v;
        }
        ksort($vary);

        return md5($request->getHost().'|'.$request->path().'|'.http_build_query($vary));
    }

    public static function get(string $key): ?string
    {
        $file = self::dir().'/'.$key.'.html';
        if (! is_file($file) || filemtime($file) < time() - self::TTL) {
            return null;
        }
        $html = @file_get_contents($file);

        return $html === false ? null : self::fill($html);
    }

    public static function put(string $key, Response $response): void
    {
        if ($response->getStatusCode() !== 200 || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return;
        }
        $html = (string) $response->getContent();
        foreach (self::HOLES as $hole) {
            if (! str_contains($html, "<!--sd:$hole-->")) {
                return; // layout sem as marcações: não guarda (evita vazar dado de visitante)
            }
            $html = preg_replace("#<!--sd:$hole-->.*?<!--/sd:$hole-->#s", "<!--sd:$hole--><!--/sd:$hole-->", $html, 1);
        }
        $html = str_replace(csrf_token(), self::CSRF, $html);

        File::ensureDirectoryExists(self::dir());
        $tmp = self::dir().'/'.$key.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($tmp, $html) !== false) {
            @rename($tmp, self::dir().'/'.$key.'.html'); // troca atômica: ninguém lê arquivo pela metade
        }
    }

    /** Preenche os trechos do visitante atual */
    private static function fill(string $html): string
    {
        $cart = app(\App\Services\Cart\CartService::class);
        $parts = [
            'cart' => view('partials.cart-data', ['cartData' => $cart->toArray($cart->current())])->render(),
            'track' => view('partials.tracking')->render(),
        ];
        foreach ($parts as $hole => $content) {
            $html = str_replace("<!--sd:$hole--><!--/sd:$hole-->", "<!--sd:$hole-->$content<!--/sd:$hole-->", $html);
        }

        return str_replace(self::CSRF, csrf_token(), $html);
    }

    /** Apaga tudo (admin mudou algo visível na loja) */
    public static function flush(): void
    {
        $dir = self::dir();
        if (! is_dir($dir)) {
            return;
        }
        // renomeia e apaga: novas páginas já começam numa pasta limpa
        $old = $dir.'-old-'.bin2hex(random_bytes(3));
        if (@rename($dir, $old)) {
            File::deleteDirectory($old);
        } else {
            File::cleanDirectory($dir);
        }
    }

    public static function stats(): array
    {
        $files = is_dir(self::dir()) ? glob(self::dir().'/*.html') : [];
        $fresh = array_filter($files, fn ($f) => filemtime($f) >= time() - self::TTL);

        return ['pages' => count($fresh), 'bytes' => array_sum(array_map('filesize', $fresh))];
    }
}
