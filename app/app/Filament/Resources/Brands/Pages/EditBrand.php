<?php

namespace App\Filament\Resources\Brands\Pages;

use App\Filament\Resources\Brands\BrandResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ver')->label('Ver na loja')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->url(fn () => route('brand.show', $this->record->slug), true),
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        Cache::forget('home.sections');
        Cache::forget('layout.menus');
    }
}
