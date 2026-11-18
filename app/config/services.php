<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'nixfy' => [
        'base_url' => env('NIXFY_BASE_URL', 'https://api.nixfypagamentos.com/api'),
        'public_key' => env('NIXFY_PUBLIC_KEY'),
        'private_key' => env('NIXFY_PRIVATE_KEY'),
        'webhook_secret' => env('NIXFY_WEBHOOK_SECRET'),   // webhook permanente (X-Nixfy-Signature)
        'postback_secret' => env('NIXFY_POSTBACK_SECRET'), // postback por venda (X-Signature)
        'pix_expires_in_days' => 1,
        'sandbox' => env('NIXFY_SANDBOX', false), // simulação local — ignorado em produção
    ],

    'meta' => [
        'pixel_id' => env('META_PIXEL_ID'),
        'capi_token' => env('META_CAPI_TOKEN'),
        'test_event_code' => env('META_TEST_EVENT_CODE'),
        'api_version' => env('META_API_VERSION', 'v24.0'),
    ],

    'google' => [
        'ga4_id' => env('GOOGLE_GA4_ID'),
        'ga4_api_secret' => env('GOOGLE_GA4_API_SECRET'),
        'ads_id' => env('GOOGLE_ADS_ID'),
        'ads_purchase_label' => env('GOOGLE_ADS_PURCHASE_LABEL'),
        'gtm_id' => env('GOOGLE_GTM_ID'),
    ],

    'tiktok' => [
        'pixel_id' => env('TIKTOK_PIXEL_ID'),
        'access_token' => env('TIKTOK_ACCESS_TOKEN'),
        'test_event_code' => env('TIKTOK_TEST_EVENT_CODE'),
    ],

    'utmify' => [
        'api_token' => env('UTMIFY_API_TOKEN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
