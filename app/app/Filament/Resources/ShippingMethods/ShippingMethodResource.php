<?php

namespace App\Filament\Resources\ShippingMethods;

use App\Filament\Support\Money;
use App\Models\ShippingMethod;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class ShippingMethodResource extends Resource
{
    protected static ?string $model = ShippingMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'modalidade de frete';

    protected static ?string $pluralModelLabel = 'frete';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canManageStore();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nome (aparece no checkout)')->required(),
            TextInput::make('description')->label('Descrição curta')->placeholder('Envio prioritário com rastreio'),
            Money::input('price', 'Preço')->required()->default(0)->helperText('0,00 = grátis'),
            Money::input('free_above', 'Grátis acima de (opcional)'),
            TextInput::make('min_days')->label('Prazo mínimo (dias úteis)')->numeric(),
            TextInput::make('max_days')->label('Prazo máximo (dias úteis)')->numeric(),
            Toggle::make('is_active')->label('Ativa no checkout')->default(true),
            Toggle::make('is_pickup')->label('É retirada na loja/academia')->helperText('Nunca vem pré-selecionada e não mostra previsão de entrega.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('position')
            ->defaultSort('position')
            ->paginated(false)
            ->description('A primeira modalidade ativa vem selecionada no checkout. Prazos e preços aparecem também na Política de Frete.')
            ->columns([
                TextColumn::make('name')->label('Modalidade')->weight('medium')->description(fn (ShippingMethod $r) => $r->description),
                TextColumn::make('price')->label('Preço')->formatStateUsing(fn ($state) => $state ? money($state) : 'Grátis'),
                TextColumn::make('free_above')->label('Grátis acima de')->formatStateUsing(fn ($state) => $state ? money($state) : '—'),
                TextColumn::make('prazo')->label('Prazo')->state(fn (ShippingMethod $r) => $r->min_days && $r->max_days ? "{$r->min_days} a {$r->max_days} dias úteis" : '—'),
                ToggleColumn::make('is_active')->label('Ativa'),
            ])
            ->headerActions([CreateAction::make()->label('Nova modalidade')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageShippingMethods::route('/')];
    }
}
