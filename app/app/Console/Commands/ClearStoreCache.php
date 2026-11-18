<?php

namespace App\Console\Commands;

use App\Support\PageCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Limpa o cache de páginas e de menus da vitrine (o deploy chama sozinho). */
class ClearStoreCache extends Command
{
    protected $signature = 'store:cache-clear';

    protected $description = 'Limpa o cache de páginas da loja';

    public function handle(): int
    {
        PageCache::flush();
        Cache::forget('layout.menus');
        Cache::forget('home.sections');
        Cache::forget('redirects.map');
        Cache::forget('health.checks');
        $this->info('Cache da loja limpo.');

        return self::SUCCESS;
    }
}
