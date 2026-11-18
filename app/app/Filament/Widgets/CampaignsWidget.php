<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\StoreAnalytics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class CampaignsWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'admin.widgets.campaigns';

    protected function getViewData(): array
    {
        return ['rows' => StoreAnalytics::fromFilters($this->pageFilters)->campaigns(15)];
    }
}
