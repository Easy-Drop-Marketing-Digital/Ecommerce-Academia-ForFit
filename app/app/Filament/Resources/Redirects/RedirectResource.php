<?php

namespace App\Filament\Resources\Redirects;

use App\Models\Redirect;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use UnitEnum;

/** Links antigos/alterados que levam para a página certa (301 — mantém Google e anúncios funcionando). */
class RedirectResource extends Resource
{
    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnRight;

    protected static string|UnitEnum|null $navigationGroup = 'Loja online';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Redirecionamentos';

    protected static ?string $modelLabel = 'redirecionamento';

    protected static ?string $pluralModelLabel = 'redirecionamentos';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canManageStore();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('from_path')->label('Link antigo')->required()->maxLength(255)
                ->placeholder('/products/vestido-antigo')
                ->helperText('Só o caminho, sem o domínio. Ex.: /products/vestido-antigo ou /collections/promocao')
                ->dehydrateStateUsing(fn ($state) => Redirect::normalize($state))
                ->rule(fn ($record) => Rule::unique('redirects', 'from_path')->ignore($record?->id)),
            TextInput::make('to_url')->label('Levar para')->required()->maxLength(500)
                ->placeholder('/products/vestido-novo')
                ->helperText('Um caminho da loja (/collections/vestidos) ou um endereço completo.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->description('Quando alguém abrir um link que não existe mais, vai direto para o destino. Ao mudar o endereço de um produto ou coleção, o redirecionamento é criado sozinho.')
            ->columns([
                TextColumn::make('from_path')->label('Link antigo')->searchable()->copyable(),
                TextColumn::make('to_url')->label('Leva para')->searchable(),
                TextColumn::make('hits')->label('Acessos')->sortable()->alignCenter(),
                TextColumn::make('last_hit_at')->label('Último acesso')->since()->sortable()->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()->label('Novo redirecionamento')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading('Nenhum redirecionamento')
            ->emptyStateDescription('Use para links antigos da Shopify que mudaram ou produtos que saíram do catálogo.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageRedirects::route('/')];
    }
}
