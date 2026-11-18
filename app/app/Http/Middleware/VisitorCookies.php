<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identificadores de 1ª parte:
 *  - sd_vid: visitante (1 ano) → external_id nos eventos (melhora a correspondência no Meta/TikTok)
 *  - sd_sid: sessão (30 min de inatividade) → sessões e funil do analytics próprio
 */
class VisitorCookies
{
    public function handle(Request $request, Closure $next): Response
    {
        $vid = $request->cookie('sd_vid');
        if (! $vid || ! Str::isUuid($vid)) {
            $vid = (string) Str::uuid();
            $request->cookies->set('sd_vid', $vid);
        }
        $sid = $request->cookie('sd_sid');
        if (! $sid || ! Str::isUuid($sid)) {
            $sid = (string) Str::uuid();
            $request->cookies->set('sd_sid', $sid);
        }

        $response = $next($request);

        Cookie::queue(Cookie::make('sd_vid', $vid, 60 * 24 * 365, '/', null, null, true, false, 'lax'));
        Cookie::queue(Cookie::make('sd_sid', $sid, 30, '/', null, null, true, false, 'lax'));

        return $response;
    }
}
