<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\StoreAnalytics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StoreStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $a = StoreAnalytics::fromFilters($this->pageFilters);
        $s = $a->summary();
        $daily = $a->daily();
        $pct = fn ($v) => $v === null ? '—' : number_format($v, 1, ',', '.').'%';

        return [
            Stat::make('Receita aprovada', money($s['revenue']))
                ->description($s['paid_orders'].' pedidos pagos')->color('success')
                ->chart(array_column($daily, 'revenue')),
            Stat::make('Ticket médio', money($s['aov']))
                ->description($s['margin'] !== null ? 'Margem bruta: '.money($s['margin']) : 'Cadastre o custo nas variações para ver a margem'),
            Stat::make('Conversão', $pct($s['conversion']))
                ->description(number_format($s['sessions'], 0, ',', '.').' sessões → '.$s['paid_orders'].' pagos'),
            Stat::make('PIX pagos', $pct($s['pix_rate']))
                ->description($s['pix_paid'].' de '.$s['pix_created'].' PIX gerados')
                ->color(($s['pix_rate'] ?? 100) < 50 ? 'warning' : 'success'),
            Stat::make('Recusa no cartão', $pct($s['card_refused_rate']))
                ->description($s['card_attempts'].' tentativas · PIX = '.$pct($s['pix_share']).' das vendas')
                ->color(($s['card_refused_rate'] ?? 0) > 30 ? 'danger' : 'gray'),
            Stat::make('Carrinhos abandonados', (string) $s['abandoned'])
                ->description('com e-mail, no período · '.$s['orders_created'].' pedidos criados')
                ->url(\App\Filament\Resources\Carts\CartResource::getUrl()),
        ];
    }
}
