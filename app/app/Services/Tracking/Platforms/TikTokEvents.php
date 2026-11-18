<?php

namespace App\Services\Tracking\Platforms;

use Illuminate\Support\Facades\Http;

/**
 * TikTok Events API v1.3 — https://business-api.tiktok.com/portal/docs?id=1771100865818625
 */
class TikTokEvents implements Platform
{
    public function name(): string
    {
        return 'tiktok';
    }

    public function enabled(): bool
    {
        return (bool) config('services.tiktok.pixel_id') && (bool) config('services.tiktok.access_token');
    }

    public function batchSize(): int
    {
        return 500;
    }

    public function send(array $events): array
    {
        $body = [
            'event_source' => 'web',
            'event_source_id' => config('services.tiktok.pixel_id'),
            'data' => array_values($events),
        ];
        if ($code = config('services.tiktok.test_event_code')) {
            $body['test_event_code'] = $code;
        }
        $res = Http::timeout(20)->connectTimeout(5)->retry(2, 500, throw: false)
            ->withHeaders(['Access-Token' => (string) config('services.tiktok.access_token')])
            ->asJson()->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', $body);
        $json = $res->json() ?? [];

        // A API devolve HTTP 200 com "code" != 0 quando há erro
        return ['ok' => $res->successful() && (int) ($json['code'] ?? 1) === 0, 'status' => $res->status(), 'body' => $json];
    }
}
