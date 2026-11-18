<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Money;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /** Cria a primeira variação com o preço/estoque informados. */
    protected function afterCreate(): void
    {
        $data = $this->form->getRawState();
        $this->record->variants()->create([
            'title' => 'Padrão',
            'price' => Money::toCents($data['initial_price'] ?? 0) ?? 0,
            'compare_at_price' => Money::toCents($data['initial_compare'] ?? null),
            'stock' => (int) ($data['initial_stock'] ?? 0),
            'sku' => $data['initial_sku'] ?? null,
            'position' => 1,
        ]);
        $this->record->update(['published_at' => now()]);
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('edit', ['record' => $this->record]);
    }
}
