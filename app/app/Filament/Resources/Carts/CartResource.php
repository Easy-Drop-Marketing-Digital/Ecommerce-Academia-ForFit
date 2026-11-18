<?php

namespace App\Filament\Resources\Carts;

use App\Models\Cart;
use App\Support\Br;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Carrinhos abandonados: quem chegou no checkout, deixou contato e não pagou. */
class CartResource extends Resource
{
    protected static ?string $model = Cart::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Vendas';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Carrinhos abandonados';

    protected static ?string $modelLabel = 'carrinho';

    protected static ?string $pluralModelLabel = 'carrinhos abandonados';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', '!=', 'converted')
                ->whereNotNull('checkout_started_at')
                ->where(fn ($w) => $w->whereNotNull('email')->orWhereNotNull('phone'))
                ->whereHas('items')
                ->with('items.variant.product'))
            ->defaultSort('checkout_started_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Cliente')->placeholder('—')->description(fn (Cart $r) => $r->email)->searchable(['name', 'email', 'phone']),
                TextColumn::make('phone')->label('Celular')->formatStateUsing(fn ($state) => $state ? Br::formatPhone($state) : '—'),
                TextColumn::make('itens')->label('Itens')->state(fn (Cart $r) => $r->items->map(fn ($i) => $i->quantity.'× '.$i->variant?->product?->title)->implode(', '))->wrap()->limit(80),
                TextColumn::make('valor')->label('Valor')->state(fn (Cart $r) => money((int) $r->items->sum(fn ($i) => ($i->variant?->price ?? 0) * $i->quantity))),
                TextColumn::make('checkout_started_at')->label('Abandonou em')->dateTime('d/m/Y H:i')->sortable()->since(),
                TextColumn::make('recovery_emails_sent')->label('E-mails de recuperação')->alignCenter(),
            ])
            ->filters([
                Filter::make('24h')->label('Últimas 24 horas')->query(fn (Builder $query) => $query->where('checkout_started_at', '>=', now()->subDay())),
                Filter::make('telefone')->label('Com celular')->query(fn (Builder $query) => $query->whereNotNull('phone')),
            ])
            ->recordActions([
                Action::make('whatsapp')->label('WhatsApp')->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('success')
                    ->visible(fn (Cart $r) => (bool) $r->phone)
                    ->url(fn (Cart $r) => 'https://wa.me/55'.Br::digits($r->phone).'?text='.rawurlencode(
                        'Oi'.($r->name ? ' '.explode(' ', $r->name)[0] : '').'! Aqui é da '.setting('store.name').' 💛 Vi que você separou algumas peças e não finalizou. Seu carrinho continua salvo aqui: '.$r->recoveryUrl()
                    ), true),
                Action::make('link')->label('Link de recuperação')->icon(Heroicon::OutlinedLink)
                    ->modalHeading('Link de recuperação do carrinho')
                    ->modalDescription(fn (Cart $r) => $r->recoveryUrl())
                    ->modalSubmitAction(false)->modalCancelActionLabel('Fechar'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCarts::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
