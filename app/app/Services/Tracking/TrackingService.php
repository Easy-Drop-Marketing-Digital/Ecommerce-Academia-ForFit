<?php

namespace App\Services\Tracking;

use App\Models\AnalyticsEvent;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\TrackingDispatch;
use App\Models\VisitorSession;
use App\Services\Tracking\Platforms\Ga4;
use App\Services\Tracking\Platforms\MetaCapi;
use App\Services\Tracking\Platforms\Platform;
use App\Services\Tracking\Platforms\TikTokEvents;
use App\Services\Tracking\Platforms\Utmify;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Central de rastreamento.
 *
 *  Navegador ──(Pixel/ttq/gtag com event_id)──► plataformas
 *      └──(beacon /t com o MESMO event_id)──► recordBrowserEvent() ──► analytics próprio + fila de envio server-side
 *  Pedido pago (webhook) ──► sendPurchase() ──► Meta CAPI, TikTok, GA4, UTMify na hora (+ fila de retentativa)
 *
 * A deduplicação Pixel × API usa event_id + event_name idênticos; o Purchase usa o event_id do pedido.
 */
class TrackingService
{
    /** nome interno → [Meta, TikTok] */
    public const EVENTS = [
        'page_view' => ['PageView', null],
        'view_item' => ['ViewContent', 'ViewContent'],
        'search' => ['Search', 'Search'],
        'add_to_cart' => ['AddToCart', 'AddToCart'],
        'begin_checkout' => ['InitiateCheckout', 'InitiateCheckout'],
        'add_payment_info' => ['AddPaymentInfo', 'AddPaymentInfo'],
        'purchase' => ['Purchase', 'CompletePayment'],
    ];

    public function __construct(
        private MetaCapi $meta,
        private TikTokEvents $tiktok,
        private Ga4 $ga4,
        private Utmify $utmify,
    ) {}

    /**
     * Registra um evento vindo do navegador.
     *
     * @param  array{name:string, event_id:string, url?:string, referrer?:?string, items?:array, search?:?string}  $e
     * @param  array{ip:?string, user_agent:?string, fbp:?string, fbc:?string, ttclid:?string, ttp:?string, visitor_id:string, session_key:string, attribution:?array}  $ctx
     */
    public function recordBrowserEvent(array $e, array $ctx, ?Cart $cart = null, ?Customer $customer = null): void
    {
        $session = $this->touchSession($ctx, $e, $customer);
        $items = $this->resolveItems($e['items'] ?? [], $cart, $e['name']);
        $value = (int) $items->sum(fn ($i) => $i['price'] * $i['quantity']);

        AnalyticsEvent::create([
            'visitor_session_id' => $session->id,
            'name' => $e['name'],
            'path' => isset($e['url']) ? mb_substr((string) parse_url($e['url'], PHP_URL_PATH), 0, 500) : null,
            'product_id' => $items->first()['product_id'] ?? null,
            'value' => $value ?: null,
            'meta' => array_filter(['event_id' => $e['event_id'], 'search' => $e['search'] ?? null, 'qty' => (int) $items->sum('quantity') ?: null]),
            'created_at' => now(),
        ]);

        if ($cart && ! $cart->visitor_id) {
            $cart->forceFill(['visitor_id' => $ctx['visitor_id']])->saveQuietly();
        }

        [$metaName, $ttName] = self::EVENTS[$e['name']];
        $user = UserData::fromContext($ctx, $cart, $customer);
        $url = $e['url'] ?? null;

        if ($metaName && $this->meta->enabled() && ($e['name'] !== 'page_view' || (bool) (int) setting('meta.capi_pageview', 1))) {
            $this->queue('meta', $metaName, $e['event_id'], $this->metaEvent($metaName, $e['event_id'], $user, $items, $value, $url, $e['search'] ?? null));
        }
        if ($ttName && $this->tiktok->enabled()) {
            $this->queue('tiktok', $ttName, $e['event_id'], $this->tiktokEvent($ttName, $e['event_id'], $user, $items, $value, $url, $e['referrer'] ?? null, $e['search'] ?? null));
        }
    }

    /** Compra confirmada: envia na hora para todas as plataformas ativas (uma vez por pedido). */
    public function sendPurchase(Order $order): void
    {
        $order->loadMissing('items.variant.product');
        if ($order->purchase_event_sent_at) {
            return;
        }

        $items = $order->items->map(fn ($i) => [
            'content_id' => $i->variant ? ContentId::for($i->variant) : (string) $i->variant_id,
            'product_id' => $i->product_id,
            'name' => $i->title,
            'price' => (int) round(($i->unit_price * $i->quantity - $i->discount) / max(1, $i->quantity)),
            'quantity' => $i->quantity,
        ]);
        $user = UserData::fromOrder($order);
        $value = (int) $order->total;
        $url = $order->page_url ?: route('order.status', $order->public_token);
        $sent = [];

        if ($this->meta->enabled()) {
            $ev = $this->metaEvent('Purchase', $order->event_id, $user, $items, $value, $url, null, $order);
            $sent[] = $this->dispatchNow('meta', 'Purchase', $order->event_id, $ev, $order);
        }
        if ($this->tiktok->enabled()) {
            $ev = $this->tiktokEvent('CompletePayment', $order->event_id, $user, $items, $value, $url, null, null, $order);
            $sent[] = $this->dispatchNow('tiktok', 'CompletePayment', $order->event_id, $ev, $order);
        }
        if ($this->ga4->enabled()) {
            $sent[] = $this->dispatchNow('ga4', 'purchase', $order->event_id, $this->ga4Purchase($order, $items), $order);
        }

        $order->forceFill(['purchase_event_sent_at' => now()])->saveQuietly();
        $order->log('tracking', 'Compra enviada às plataformas: '.(implode(', ', array_filter($sent)) ?: 'nenhuma configurada'), ['match_params' => $user->score()]);
    }

    /** UTMify recebe o pedido em cada mudança de status (aguardando, pago, recusado, reembolsado...). */
    public function sendUtmify(Order $order): void
    {
        if (! $this->utmify->enabled()) {
            return;
        }
        $order->loadMissing('items');
        $this->dispatchNow('utmify', $order->payment_status, 'ord'.$order->id.'_'.$order->payment_status, Utmify::payload($order), $order);
    }

    /** Envia a fila pendente em lotes (agendado a cada minuto). */
    public function flush(int $limitPerPlatform = 2000): array
    {
        $report = [];
        foreach ([$this->meta, $this->tiktok, $this->ga4, $this->utmify] as $platform) {
            if (! $platform->enabled()) {
                continue;
            }
            $pending = TrackingDispatch::where('platform', $platform->name())
                ->whereIn('status', ['pending', 'failed'])->where('attempts', '<', 5)
                ->where(fn ($q) => $q->whereNull('send_after')->orWhere('send_after', '<=', now()))
                ->orderBy('id')->limit($limitPerPlatform)->get();

            foreach ($pending->chunk($platform->batchSize()) as $chunk) {
                $report[$platform->name()] = ($report[$platform->name()] ?? 0) + $this->sendBatch($platform, $chunk);
            }
        }

        return $report;
    }

    // ------------------------------------------------------------------ internos

    private function queue(string $platform, string $event, string $eventId, array $payload, ?Order $order = null): TrackingDispatch
    {
        return TrackingDispatch::create([
            'platform' => $platform,
            'event_name' => $event,
            'event_id' => $eventId,
            'subject_type' => $order ? Order::class : null,
            'subject_id' => $order?->id,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }

    private function dispatchNow(string $platform, string $event, string $eventId, array $payload, Order $order): ?string
    {
        $d = $this->queue($platform, $event, $eventId, $payload, $order);
        $p = $this->platform($platform);
        $this->sendBatch($p, collect([$d]));

        return $d->fresh()->status === 'sent' ? $platform : null;
    }

    private function sendBatch(Platform $platform, Collection $chunk): int
    {
        try {
            $res = $platform->send($chunk->pluck('payload')->all());
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'status' => 0, 'body' => ['error' => $e->getMessage()]];
        }

        foreach ($chunk as $d) {
            $attempts = $d->attempts + 1;
            $d->forceFill([
                'status' => $res['ok'] ? 'sent' : 'failed',
                'attempts' => $attempts,
                'http_status' => $res['status'],
                'response' => $res['body'],
                'last_error' => $res['ok'] ? null : mb_substr(json_encode($res['body'], JSON_UNESCAPED_UNICODE), 0, 1000),
                // espera crescente entre tentativas: 1, 4, 9, 16 min
                'send_after' => $res['ok'] ? null : now()->addMinutes($attempts ** 2),
            ])->save();
        }

        return $res['ok'] ? $chunk->count() : 0;
    }

    private function platform(string $name): Platform
    {
        return match ($name) {
            'meta' => $this->meta,
            'tiktok' => $this->tiktok,
            'ga4' => $this->ga4,
            'utmify' => $this->utmify,
        };
    }

    /** Itens do evento a partir do ID da variação (preço sempre do banco, nunca do navegador). */
    private function resolveItems(array $items, ?Cart $cart, string $name): Collection
    {
        // Checkout/pagamento: o conteúdo é o carrinho inteiro
        if (in_array($name, ['begin_checkout', 'add_payment_info'], true) && $cart) {
            $items = $cart->items->map(fn ($i) => ['variant_id' => $i->variant_id, 'quantity' => $i->quantity])->all();
        }
        $items = collect($items)->take(50)->filter(fn ($i) => isset($i['variant_id']) && is_numeric($i['variant_id']));
        if ($items->isEmpty()) {
            return collect();
        }
        $variants = ProductVariant::with('product:id,title,shopify_id')->whereIn('id', $items->pluck('variant_id'))->get()->keyBy('id');

        return $items->map(function ($i) use ($variants) {
            $v = $variants->get((int) $i['variant_id']);

            return $v ? [
                'content_id' => ContentId::for($v),
                'product_id' => $v->product_id,
                'name' => $v->product->title,
                'price' => $v->price,
                'quantity' => max(1, min(99, (int) ($i['quantity'] ?? 1))),
            ] : null;
        })->filter()->values();
    }

    private function metaEvent(string $name, string $eventId, UserData $user, Collection $items, int $value, ?string $url, ?string $search = null, ?Order $order = null): array
    {
        $custom = array_filter([
            'currency' => $items->isNotEmpty() || $order ? 'BRL' : null,
            'value' => $items->isNotEmpty() || $order ? round($value / 100, 2) : null,
            'content_type' => $items->isNotEmpty() ? 'product' : null,
            'content_ids' => $items->pluck('content_id')->unique()->values()->all() ?: null,
            'contents' => $items->map(fn ($i) => ['id' => $i['content_id'], 'quantity' => $i['quantity'], 'item_price' => round($i['price'] / 100, 2)])->all() ?: null,
            'num_items' => $items->sum('quantity') ?: null,
            'order_id' => $order ? (string) $order->number : null,
            'search_string' => $search,
        ], fn ($v) => $v !== null);

        return array_filter([
            'event_name' => $name,
            'event_time' => $order?->paid_at?->timestamp ?? time(),
            'event_id' => $eventId,
            'event_source_url' => $url,
            'action_source' => 'website',
            'user_data' => $user->forMeta(),
            'custom_data' => $custom ?: null,
        ], fn ($v) => $v !== null);
    }

    private function tiktokEvent(string $name, string $eventId, UserData $user, Collection $items, int $value, ?string $url, ?string $referrer = null, ?string $search = null, ?Order $order = null): array
    {
        return [
            'event' => $name,
            'event_time' => $order?->paid_at?->timestamp ?? time(),
            'event_id' => $eventId,
            'user' => $user->forTikTok() ?: new \stdClass,
            'page' => array_filter(['url' => $url, 'referrer' => $referrer]),
            'properties' => array_filter([
                'currency' => 'BRL',
                'value' => round($value / 100, 2),
                'content_type' => $items->isNotEmpty() ? 'product' : null,
                'contents' => $items->map(fn ($i) => ['content_id' => $i['content_id'], 'content_name' => $i['name'], 'quantity' => $i['quantity'], 'price' => round($i['price'] / 100, 2)])->all() ?: null,
                'order_id' => $order ? (string) $order->number : null,
                'query' => $search,
            ], fn ($v) => $v !== null),
        ];
    }

    private function ga4Purchase(Order $o, Collection $items): array
    {
        return [
            'client_id' => $o->ga_client_id ?: ($o->visitor_id ?: 'ord.'.$o->id),
            'events' => [[
                'name' => 'purchase',
                'params' => [
                    'transaction_id' => (string) $o->number,
                    'currency' => 'BRL',
                    'value' => round($o->total / 100, 2),
                    'shipping' => round($o->shipping_total / 100, 2),
                    'coupon' => $o->discount_codes,
                    'items' => $items->map(fn ($i) => ['item_id' => $i['content_id'], 'item_name' => $i['name'], 'price' => round($i['price'] / 100, 2), 'quantity' => $i['quantity']])->all(),
                ],
            ]],
        ];
    }

    private function touchSession(array $ctx, array $e, ?Customer $customer): VisitorSession
    {
        $s = VisitorSession::firstOrNew(['session_key' => $ctx['session_key']]);
        if (! $s->exists) {
            $attr = $ctx['attribution'] ?? [];
            $s->fill([
                'visitor_id' => $ctx['visitor_id'],
                'landing_page' => isset($e['url']) ? mb_substr($e['url'], 0, 500) : null,
                'referrer' => isset($e['referrer']) ? mb_substr((string) $e['referrer'], 0, 500) : null,
                'source' => self::classifySource($attr, $e['referrer'] ?? null, $e['url'] ?? null),
                'utm_source' => $attr['utm_source'] ?? null,
                'utm_medium' => $attr['utm_medium'] ?? null,
                'utm_campaign' => $attr['utm_campaign'] ?? null,
                'utm_content' => $attr['utm_content'] ?? null,
                'utm_term' => $attr['utm_term'] ?? null,
                'device' => self::device((string) ($ctx['user_agent'] ?? '')),
                'country' => 'BR',
                'pageviews' => 0,
                'started_at' => now(),
            ]);
        }
        if ($e['name'] === 'page_view') {
            $s->pageviews++;
        }
        $s->customer_id = $customer?->id ?? $s->customer_id;
        $s->last_seen_at = now();
        $s->save();

        return $s;
    }

    /** Origem da sessão para os relatórios: meta, google, tiktok, email, organico, referencia, direto */
    public static function classifySource(array $attr, ?string $referrer, ?string $url = null): string
    {
        $src = Str::lower((string) ($attr['utm_source'] ?? ''));
        $medium = Str::lower((string) ($attr['utm_medium'] ?? ''));
        $ref = Str::lower((string) parse_url((string) $referrer, PHP_URL_HOST));
        $query = [];
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return match (true) {
            ! empty($attr['fbclid']) || ! empty($query['fbclid']) || Str::contains($src, ['facebook', 'instagram', 'fb', 'ig', 'meta']) => 'meta',
            ! empty($attr['ttclid']) || ! empty($query['ttclid']) || Str::contains($src, 'tiktok') => 'tiktok',
            ! empty($attr['gclid']) || ! empty($query['gclid']) || ($src === 'google' && Str::contains($medium, ['cpc', 'ads', 'paid'])) => 'google_ads',
            Str::contains($medium, 'email') || Str::contains($src, ['email', 'newsletter']) => 'email',
            $src !== '' => $src,
            Str::contains($ref, ['google.', 'bing.', 'yahoo.', 'duckduckgo.']) => 'organico',
            Str::contains($ref, ['facebook.', 'instagram.', 'fb.']) => 'meta',
            Str::contains($ref, 'tiktok.') => 'tiktok',
            $ref !== '' => 'referencia',
            default => 'direto',
        };
    }

    public static function device(string $ua): string
    {
        $ua = Str::lower($ua);

        return match (true) {
            Str::contains($ua, ['ipad', 'tablet']) => 'tablet',
            Str::contains($ua, ['mobi', 'android', 'iphone']) => 'mobile',
            default => 'desktop',
        };
    }

    public static function isBot(?string $ua): bool
    {
        return $ua === null || $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|lighthouse|headless|preview|monitor|curl|wget|python|axios/i', $ua);
    }
}
