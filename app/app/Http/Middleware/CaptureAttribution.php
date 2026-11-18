<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarda a origem da visita (UTMs e IDs de clique dos anúncios) para atribuir o pedido à campanha certa
 * e enviar fbc/fbp ao Meta (aumenta a qualidade de correspondência dos eventos).
 *
 * - "last touch" não-direto vai para a sessão (attribution) e para o carrinho
 * - fbclid vira o cookie _fbc no formato do Meta (fb.1.<timestamp_ms>.<fbclid>), se o Pixel ainda não criou
 */
class CaptureAttribution
{
    private const KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'ttclid', 'src', 'sck'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            $params = array_filter(array_map(fn ($k) => $request->query($k) ? mb_substr((string) $request->query($k), 0, 255) : null, array_combine(self::KEYS, self::KEYS)));

            if ($params) {
                $attr = $params + [
                    'landing' => mb_substr($request->fullUrl(), 0, 500),
                    'referrer' => mb_substr((string) $request->headers->get('referer'), 0, 500) ?: null,
                    'at' => now()->toIso8601String(),
                ];
                if (! $request->session()->has('attribution_first')) {
                    $request->session()->put('attribution_first', $attr);
                }
                $request->session()->put('attribution', $attr);

                if (! empty($params['fbclid']) && ! $request->cookie('_fbc')) {
                    Cookie::queue(Cookie::make('_fbc', 'fb.1.'.(int) (microtime(true) * 1000).'.'.$params['fbclid'], 60 * 24 * 90, '/', null, null, false, true, 'lax'));
                }
            } elseif (! $request->session()->has('attribution') && ($ref = $request->headers->get('referer'))) {
                $host = parse_url($ref, PHP_URL_HOST);
                if ($host && $host !== $request->getHost()) {
                    $request->session()->put('attribution', ['referrer' => mb_substr($ref, 0, 500), 'landing' => mb_substr($request->fullUrl(), 0, 500), 'at' => now()->toIso8601String()]);
                }
            }
        }

        return $next($request);
    }
}
