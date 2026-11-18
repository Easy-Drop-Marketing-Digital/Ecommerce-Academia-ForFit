<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Tracking\ProductFeed;
use Illuminate\Support\Facades\Storage;

/**
 * Serve o feed de produtos gerado em arquivo (comando feeds:build, agendado a cada 30 min).
 *   /feeds/produtos.xml → cadastrar como feed agendado no Meta Commerce Manager, Google Merchant e TikTok Catalog
 */
class FeedController extends Controller
{
    public function products(ProductFeed $feed)
    {
        if (! Storage::disk('local')->exists(ProductFeed::PATH)) {
            $feed->build();
        }

        return response()->file(Storage::disk('local')->path(ProductFeed::PATH), [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }
}
