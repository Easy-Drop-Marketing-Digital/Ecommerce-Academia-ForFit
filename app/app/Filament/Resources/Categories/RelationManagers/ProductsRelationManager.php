<?php

namespace App\Filament\Resources\Categories\RelationManagers;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Produtos da coleção';

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('position')
            ->defaultSort('category_product.position')
            ->description('Arraste para definir a ordem em que os produtos aparecem na coleção e nas vitrines da home.')
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with(['images' => fn ($i) => $i->orderBy('position')->limit(1)]))
            ->columns([
                ImageColumn::make('thumb')->label('')->state(fn (Product $r) => $r->images->first() ? img_url($r->images->first()->path, 120) : null)->imageHeight(48),
                TextColumn::make('title')->label('Produto')->searchable()->wrap(),
                TextColumn::make('min_price')->label('Preço')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('status')->label('Situação')->badge()->color(fn ($state) => $state === 'active' ? 'success' : 'warning'),
            ])
            ->headerActions([
                AttachAction::make()->label('Adicionar produtos')->multiple()->preloadRecordSelect()->recordSelectSearchColumns(['title'])
                    ->after(fn () => Cache::forget('home.sections')),
            ])
            ->recordActions([
                Action::make('editar')->label('Editar')->url(fn (Product $r) => ProductResource::getUrl('edit', ['record' => $r])),
                DetachAction::make()->label('Remover da coleção')->after(fn () => Cache::forget('home.sections')),
            ])
            ->toolbarActions([DetachBulkAction::make()->label('Remover da coleção')]);
    }
}
