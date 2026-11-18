<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\StoreAnalytics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class RevenueChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Receita aprovada por dia';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $daily = StoreAnalytics::fromFilters($this->pageFilters)->daily();

        return [
            'datasets' => [
                [
                    'label' => 'Receita (R$)',
                    'data' => array_map(fn ($d) => round($d['revenue'] / 100, 2), $daily),
                    'borderColor' => '#16c789',
                    'backgroundColor' => 'rgba(22, 199, 137, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                    'yAxisID' => 'y',
                ],
                [
                    'label' => 'Pedidos pagos',
                    'data' => array_column($daily, 'orders'),
                    'borderColor' => '#111827',
                    'type' => 'bar',
                    'backgroundColor' => 'rgba(17, 24, 39, 0.15)',
                    'yAxisID' => 'y1',
                ],
            ],
            'labels' => array_column($daily, 'label'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true, 'position' => 'left'],
                'y1' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false], 'ticks' => ['precision' => 0]],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
