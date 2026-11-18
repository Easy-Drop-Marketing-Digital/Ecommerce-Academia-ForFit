<?php

namespace App\Filament\Resources\Discounts\Pages;

use App\Filament\Resources\Discounts\DiscountResource;
use Filament\Resources\Pages\ListRecords;

class ListDiscounts extends ListRecords
{
    protected static string $resource = DiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()->label('Novo')];
    }
}
