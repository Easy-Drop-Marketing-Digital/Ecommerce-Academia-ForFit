<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\StoreAnalytics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class TopProductsWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 4;

    protected string $view = 'admin.widgets.top-products';

    protected function getViewData(): array
    {
        return ['rows' => StoreAnalytics::fromFilters($this->pageFilters)->topProducts(10)];
    }
}
