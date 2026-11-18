<?php

namespace App\Filament\Resources\Discounts\Pages;

use App\Filament\Resources\Discounts\DiscountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDiscount extends EditRecord
{
    use SyncsDiscountTargets;

    protected static string $resource = DiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillExtras($data, $this->record);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->normalize($data);
    }

    protected function afterSave(): void
    {
        $this->syncTargets($this->record);
    }
}
