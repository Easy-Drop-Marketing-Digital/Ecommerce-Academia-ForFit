<?php

namespace App\Filament\Resources\Stock;

use App\Filament\Resources\Products\ProductResource;
use App\Models\ProductVariant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/** Visão de estoque por variação: todas as peças da loja numa tabela só, editável. */
class StockResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Estoque';

    protected static ?string $modelLabel = 'variação';

    protected static ?string $pluralModelLabel = 'estoque';

    protected static ?string $slug = 'estoque';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('product:id,title,status')->whereHas('product'))
            ->defaultSort('stock')
            ->columns([
                TextColumn::make('product.title')->label('Produto')->searchable()->wrap()->limit(60),
                TextColumn::make('title')->label('Variação')->searchable(),
                TextColumn::make('sku')->label('SKU')->searchable()->placeholder('—')->toggleable(),
                TextInputColumn::make('stock')->label('Estoque')->type('number')->rules(['integer'])->width('110px')->sortable()
                    ->updateStateUsing(fn (ProductVariant $record, $state) => $record->adjustStock((int) $state - $record->stock, 'manual', null, auth()->id())),
                TextColumn::make('price')->label('Preço')->formatStateUsing(fn ($state) => money($state))->sortable(),
            ])
            ->filters([
                Filter::make('zerado')->label('Esgotados')->query(fn (Builder $query) => $query->where('stock', '<=', 0)),
                Filter::make('baixo')->label('Estoque baixo (menos de 10)')->query(fn (Builder $query) => $query->where('stock', '<', 10)),
                Filter::make('ativos')->label('Só produtos ativos')->default()
                    ->query(fn (Builder $query) => $query->where('is_active', true)->whereHas('product', fn ($p) => $p->where('status', 'active'))),
            ])
            ->recordActions([
                Action::make('produto')->label('Abrir produto')->url(fn (ProductVariant $r) => ProductResource::getUrl('edit', ['record' => $r->product_id])),
            ])
            ->toolbarActions([
                BulkAction::make('definir')->label('Definir estoque')->icon(Heroicon::OutlinedCube)
                    ->schema([TextInput::make('stock')->label('Estoque')->numeric()->required()->default(1000)])
                    ->action(function (Collection $records, array $data) {
                        $records->each(fn ($v) => $v->adjustStock((int) $data['stock'] - $v->stock, 'manual', null, auth()->id()));
                        Notification::make()->title('Estoque atualizado em '.$records->count().' variação(ões)')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageStock::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
