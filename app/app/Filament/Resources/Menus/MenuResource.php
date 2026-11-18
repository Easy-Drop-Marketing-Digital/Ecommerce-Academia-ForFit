<?php

namespace App\Filament\Resources\Menus;

use App\Models\Menu;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static string|UnitEnum|null $navigationGroup = 'Loja online';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'menu';

    protected static ?string $pluralModelLabel = 'menus';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nome')->required(),
            TextInput::make('handle')->label('Código')->required()->alphaDash()->unique(ignoreRecord: true)
                ->helperText('main = menu do topo; footer-colecoes / footer-informacoes / footer-politicas = colunas do rodapé.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->withCount('allItems'))
            ->columns([
                TextColumn::make('name')->label('Menu')->weight('medium'),
                TextColumn::make('handle')->label('Onde aparece')->formatStateUsing(fn ($state) => [
                    'main' => 'Topo do site', 'footer-colecoes' => 'Rodapé — 1ª coluna', 'footer-informacoes' => 'Rodapé — 2ª coluna', 'footer-politicas' => 'Rodapé — 3ª coluna',
                ][$state] ?? $state),
                TextColumn::make('all_items_count')->label('Links')->alignCenter(),
            ])
            ->headerActions([CreateAction::make()->label('Novo menu')])
            ->recordActions([EditAction::make()->label('Editar links')]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMenus::route('/'),
            'edit' => Pages\EditMenu::route('/{record}/edit'),
        ];
    }
}
