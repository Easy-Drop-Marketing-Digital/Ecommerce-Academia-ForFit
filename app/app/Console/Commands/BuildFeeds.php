<?php

namespace App\Console\Commands;

use App\Services\Tracking\ProductFeed;
use Illuminate\Console\Command;

/** Regera o feed de produtos (Meta/Google/TikTok). Agendado a cada 30 minutos. */
class BuildFeeds extends Command
{
    protected $signature = 'feeds:build';

    protected $description = 'Gera o feed de produtos em storage/app/private/feeds/produtos.xml';

    public function handle(ProductFeed $feed): int
    {
        $t = microtime(true);
        $n = $feed->build();
        $this->info("$n itens em ".round(microtime(true) - $t, 1).'s');

        return self::SUCCESS;
    }
}
