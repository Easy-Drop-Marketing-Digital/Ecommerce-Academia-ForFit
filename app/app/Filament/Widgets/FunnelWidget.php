<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\StoreAnalytics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class FunnelWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected string $view = 'admin.widgets.funnel';

    protected function getViewData(): array
    {
        $a = StoreAnalytics::fromFilters($this->pageFilters);

        return ['steps' => $a->funnel(), 'sources' => $a->sources()];
    }
}
