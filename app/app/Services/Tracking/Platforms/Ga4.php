<?php

namespace App\Services\Tracking\Platforms;

use Illuminate\Support\Facades\Http;

/**
 * GA4 Measurement Protocol — só para a compra (garante o "purchase" mesmo quando a cliente paga o PIX
 * e não volta para o site). O GA4 deduplica pelo transaction_id com o evento do navegador.
 */
class Ga4 implements Platform
{
    public function name(): string
    {
        return 'ga4';
    }

    public function enabled(): bool
    {
        return (bool) config('services.google.ga4_id') && (bool) config('services.google.ga4_api_secret');
    }

    public function batchSize(): int
    {
        return 1; // cada evento tem seu client_id
    }

    public function send(array $events): array
    {
        $e = array_values($events)[0];
        $res = Http::timeout(15)->connectTimeout(5)->retry(2, 500, throw: false)->asJson()
            ->post('https://www.google-analytics.com/mp/collect?'.http_build_query([
                'measurement_id' => config('services.google.ga4_id'),
                'api_secret' => config('services.google.ga4_api_secret'),
            ]), $e);

        return ['ok' => $res->status() >= 200 && $res->status() < 300, 'status' => $res->status(), 'body' => ['raw' => mb_substr($res->body(), 0, 300)]];
    }
}
