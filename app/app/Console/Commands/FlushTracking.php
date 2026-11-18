<?php

namespace App\Console\Commands;

use App\Services\Tracking\TrackingService;
use Illuminate\Console\Command;

/** Envia em lote os eventos server-side pendentes (Meta CAPI, TikTok...). Agendado a cada minuto. */
class FlushTracking extends Command
{
    protected $signature = 'tracking:flush';

    protected $description = 'Envia em lote os eventos de rastreamento pendentes para as plataformas de anúncio';

    public function handle(TrackingService $tracking): int
    {
        $report = $tracking->flush();
        $this->info($report ? collect($report)->map(fn ($n, $p) => "$p: $n")->implode(' | ') : 'Nada pendente.');

        return self::SUCCESS;
    }
}
