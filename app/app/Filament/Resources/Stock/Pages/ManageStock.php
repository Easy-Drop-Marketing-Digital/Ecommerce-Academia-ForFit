<?php

namespace App\Filament\Resources\Stock\Pages;

use App\Filament\Resources\Stock\StockResource;
use Filament\Resources\Pages\ManageRecords;

class ManageStock extends ManageRecords
{
    protected static string $resource = StockResource::class;
}
