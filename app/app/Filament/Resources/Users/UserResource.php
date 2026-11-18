<?php

namespace App\Filament\Resources\Users;

use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 9;

    protected static ?string $modelLabel = 'usuário do admin';

    protected static ?string $pluralModelLabel = 'usuários do admin';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isOwner();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nome')->required(),
            TextInput::make('email')->label('E-mail')->email()->required()->unique(ignoreRecord: true),
            Select::make('role')->label('Papel')->options(User::ROLES)->required()->default('staff')
                ->helperText('Dono: tudo. Administrador: tudo exceto usuários. Equipe: pedidos, clientes, produtos, estoque e avaliações.'),
            TextInput::make('password')->label('Senha')->password()->revealable()->minLength(10)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->helperText('Mínimo 10 caracteres. Em branco ao editar = mantém a atual.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nome')->weight('medium')->description(fn (User $r) => $r->email),
                TextColumn::make('role')->label('Papel')->badge()->formatStateUsing(fn ($state) => User::ROLES[$state] ?? $state),
                TextColumn::make('created_at')->label('Criado em')->date('d/m/Y'),
            ])
            ->headerActions([CreateAction::make()->label('Novo usuário')])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->hidden(fn (User $r) => $r->id === auth()->id()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageUsers::route('/')];
    }
}
