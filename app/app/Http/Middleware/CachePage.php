<?php

namespace App\Http\Middleware;

use App\Support\PageCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Serve a vitrine do cache de página quando possível (ver App\Support\PageCache). */
class CachePage
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = PageCache::key($request);
        if ($key && ($html = PageCache::get($key)) !== null) {
            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Page-Cache' => 'HIT']);
        }

        $response = $next($request);

        if ($key) {
            PageCache::put($key, $response);
            $response->headers->set('X-Page-Cache', 'MISS');
        }

        return $response;
    }
}
