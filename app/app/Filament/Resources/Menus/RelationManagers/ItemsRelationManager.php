<?php

namespace App\Filament\Resources\Menus\RelationManagers;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Product;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'allItems';

    protected static ?string $title = 'Links do menu';

    public function form(Schema $schema): Schema
    {
        $menuId = $this->getOwnerRecord()->id;

        return $schema->columns(2)->components([
            TextInput::make('title')->label('Texto do link')->required(),
            Select::make('link_type')->label('Aponta para')->required()->live()->default('category')
                ->options(['category' => 'Coleção', 'product' => 'Produto', 'page' => 'Página / política', 'url' => 'Endereço livre', 'home' => 'Página inicial'])
                ->afterStateUpdated(fn (Set $set) => $set('link_id', null)),
            Select::make('link_id')->label('Escolha')->searchable()
                ->options(fn (Get $get) => match ($get('link_type')) {
                    'category' => Category::orderBy('name')->pluck('name', 'id'),
                    'product' => Product::active()->orderBy('title')->pluck('title', 'id'),
                    'page' => Page::orderBy('title')->pluck('title', 'id'),
                    default => [],
                })
                ->visible(fn (Get $get) => in_array($get('link_type'), ['category', 'product', 'page'], true))
                ->required(fn (Get $get) => in_array($get('link_type'), ['category', 'product', 'page'], true)),
            TextInput::make('url')->label('Endereço')->placeholder('/collections/black-friday ou https://...')
                ->visible(fn (Get $get) => $get('link_type') === 'url')->required(fn (Get $get) => $get('link_type') === 'url'),
            Select::make('parent_id')->label('Submenu de (opcional)')
                ->options(fn (?MenuItem $record) => MenuItem::where('menu_id', $menuId)->whereNull('parent_id')
                    ->when($record, fn ($q) => $q->whereKeyNot($record->id))->pluck('title', 'id')),
        ]);
    }

    public function table(Table $table): Table
    {
        $forget = fn () => Cache::forget('layout.menus');

        return $table
            ->reorderable('position')
            ->defaultSort('position')
            ->paginated(false)
            ->description('Arraste para reordenar. No topo do site, os 5 primeiros aparecem direto e o restante em "Mais".')
            ->columns([
                TextColumn::make('title')->label('Link')->weight('medium')
                    ->formatStateUsing(fn ($state, MenuItem $r) => ($r->parent_id ? '↳ ' : '').$state),
                TextColumn::make('destino')->label('Destino')->state(fn (MenuItem $r) => parse_url($r->href(), PHP_URL_PATH) ?: $r->href())->color('gray'),
            ])
            ->headerActions([CreateAction::make()->label('Adicionar link')->after($forget)])
            ->recordActions([EditAction::make()->after($forget), DeleteAction::make()->after($forget)])
            ->reorderRecordsTriggerAction(fn ($action) => $action->after($forget));
    }
}
