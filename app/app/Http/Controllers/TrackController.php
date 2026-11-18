<?php

namespace App\Http\Controllers;

use App\Services\Cart\CartService;
use App\Services\Tracking\TrackingService;
use Illuminate\Http\Request;

/**
 * Recebe os eventos do navegador (sendBeacon) — mesmo event_id usado pelo Pixel, para deduplicação.
 * Responde 204 sempre: rastreamento nunca pode atrapalhar a navegação.
 */
class TrackController extends Controller
{
    public function __invoke(Request $request, TrackingService $tracking, CartService $carts)
    {
        // sendBeacon envia text/plain: decodifica o corpo manualmente
        $data = $request->json()->all() ?: (json_decode($request->getContent(), true) ?: []);

        $name = $data['name'] ?? null;
        $eventId = $data['event_id'] ?? null;
        if (! isset(TrackingService::EVENTS[$name]) || ! is_string($eventId) || ! preg_match('/^[A-Za-z0-9_.-]{8,64}$/', $eventId)) {
            return response()->noContent();
        }
        if (TrackingService::isBot($request->userAgent())) {
            return response()->noContent();
        }
        // Purchase só pelo servidor (webhook): evita que alguém "fabrique" compras pelo navegador
        if ($name === 'purchase') {
            return response()->noContent();
        }

        $attr = $request->session()->get('attribution') ?? [];

        try {
            $tracking->recordBrowserEvent([
                'name' => $name,
                'event_id' => $eventId,
                'url' => $this->sameOriginUrl($data['url'] ?? null, $request),
                'referrer' => isset($data['referrer']) ? mb_substr((string) $data['referrer'], 0, 500) : null,
                'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
                'search' => isset($data['search']) ? mb_substr((string) $data['search'], 0, 120) : null,
            ], [
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'fbp' => $request->cookie('_fbp'),
                'fbc' => $request->cookie('_fbc'),
                'ttclid' => $attr['ttclid'] ?? null,
                'ttp' => $request->cookie('_ttp'),
                'visitor_id' => $request->cookie('sd_vid'),
                'session_key' => $request->cookie('sd_sid'),
                'attribution' => $attr,
            ], $carts->current(), auth('customer')->user());
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->noContent();
    }

    private function sameOriginUrl(?string $url, Request $request): ?string
    {
        if (! $url || parse_url($url, PHP_URL_HOST) !== $request->getHost()) {
            return url()->previous() ?: null;
        }

        return mb_substr($url, 0, 500);
    }
}
