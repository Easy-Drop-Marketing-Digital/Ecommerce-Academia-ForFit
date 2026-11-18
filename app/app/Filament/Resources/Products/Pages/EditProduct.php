<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ver')->label('Ver na loja')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->url(fn () => route('product.show', $this->record->slug), true),
            DeleteAction::make()->label('Excluir'),
            RestoreAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $this->record->refreshPriceCache();
        Cache::forget('home.sections');
    }
}
