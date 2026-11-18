<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CampaignsWidget;
use App\Filament\Widgets\FunnelWidget;
use App\Filament\Widgets\RevenueChart;
use App\Filament\Widgets\StoreStats;
use App\Filament\Widgets\TopProductsWidget;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Painel';

    protected static ?string $navigationLabel = 'Painel';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')->label('Período')->default('7d')->selectablePlaceholder(false)->options([
                'today' => 'Hoje', 'yesterday' => 'Ontem', '7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias',
                'month' => 'Este mês', 'custom' => 'Personalizado',
            ]),
            DatePicker::make('start')->label('De')->visible(fn (Get $get) => $get('period') === 'custom'),
            DatePicker::make('end')->label('Até')->visible(fn (Get $get) => $get('period') === 'custom'),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\HealthAlert::class,
            StoreStats::class,
            RevenueChart::class,
            FunnelWidget::class,
            TopProductsWidget::class,
            CampaignsWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
