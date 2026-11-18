@php
    /*
     * Configuração dos pixels para o navegador. Tudo vem de Admin → Configurações;
     * campo vazio = pixel não carrega. Correspondência avançada vai sempre em hash SHA-256.
     */
    $cartModel = app(\App\Services\Cart\CartService::class)->current();
    $customer = auth('customer')->user();
    $ud = \App\Services\Tracking\UserData::fromContext(['visitor_id' => request()->cookie('sd_vid')], $cartModel, $customer);
    if (isset($trackOrder)) {
        $ud = \App\Services\Tracking\UserData::fromOrder($trackOrder);
    }
    $phone = $ud->get('phone');
    $sdTrack = array_filter([
        'meta' => config('services.meta.pixel_id') ?: null,
        'am' => config('services.meta.pixel_id') ? $ud->forPixel() : null,
        'tiktok' => config('services.tiktok.pixel_id') ?: null,
        'tt' => config('services.tiktok.pixel_id') ? array_filter([
            'email' => \App\Services\Tracking\UserData::hash($ud->get('email')),
            'phone_number' => $phone ? hash('sha256', '+'.$phone) : null,
            'external_id' => \App\Services\Tracking\UserData::hash($ud->get('external_id')),
        ]) : null,
        'ga4' => config('services.google.ga4_id') ?: null,
        'ads' => config('services.google.ads_id') ?: null,
        'adsLabel' => config('services.google.ads_purchase_label') ?: null,
        'gtm' => config('services.google.gtm_id') ?: null,
        'gu' => config('services.google.ads_id') ? array_filter([
            'sha256_email_address' => \App\Services\Tracking\UserData::hash($ud->get('email')),
            'sha256_phone_number' => $phone ? hash('sha256', '+'.$phone) : null,
        ]) : null,
    ]);
@endphp
@if (config('services.meta.pixel_id'))<link rel="preconnect" href="https://connect.facebook.net">@endif
<script>window.SD_TRACK = @json($sdTrack ?: new \stdClass); window.sdq = window.sdq || [];</script>
