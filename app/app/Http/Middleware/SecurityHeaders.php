<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança em todas as respostas (loja e admin).
 * Sem Content-Security-Policy de propósito: Pixel/GTM/TikTok carregam scripts de vários domínios
 * configurados no admin, e uma CSP rígida quebraria rastreamento sem aviso.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'SAMEORIGIN');            // ninguém coloca a loja/checkout dentro de iframe
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->isSecure() && app()->isProduction()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000');
        }
        $h->remove('X-Powered-By');

        return $response;
    }
}
