<?php

namespace App\Services\Tracking\Platforms;

use Illuminate\Support\Facades\Http;

/**
 * Meta Conversions API — https://developers.facebook.com/docs/marketing-api/conversions-api
 * Envio em lote (até 1000 eventos por chamada; usamos 500).
 */
class MetaCapi implements Platform
{
    public function name(): string
    {
        return 'meta';
    }

    public function enabled(): bool
    {
        return (bool) config('services.meta.pixel_id') && (bool) config('services.meta.capi_token');
    }

    public function batchSize(): int
    {
        return 500;
    }

    /** @param  array<int, array>  $events  eventos já no formato do Meta */
    public function send(array $events): array
    {
        $body = ['data' => array_values($events)];
        if ($code = config('services.meta.test_event_code')) {
            $body['test_event_code'] = $code;
        }
        $url = sprintf('https://graph.facebook.com/%s/%s/events', config('services.meta.api_version', 'v24.0'), config('services.meta.pixel_id'));
        $res = Http::timeout(20)->connectTimeout(5)->retry(2, 500, throw: false)
            ->asJson()->post($url.'?access_token='.urlencode((string) config('services.meta.capi_token')), $body);

        return ['ok' => $res->successful(), 'status' => $res->status(), 'body' => $res->json() ?? ['raw' => mb_substr($res->body(), 0, 500)]];
    }
}
