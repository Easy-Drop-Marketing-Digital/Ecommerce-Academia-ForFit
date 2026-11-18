<?php

namespace App\Filament\Resources\Discounts;

use App\Filament\Support\Money;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Descontos, cupons e promoções (o cálculo fica em App\Services\Checkout\DiscountEngine).
 */
class DiscountResource extends Resource
{
    protected static ?string $model = Discount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'desconto';

    protected static ?string $pluralModelLabel = 'descontos e cupons';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canManageStore();
    }

    public static function form(Schema $schema): Schema
    {
        $is = fn (string ...$types) => fn (Get $get) => in_array($get('type'), $types, true);

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                Grid::make(1)->columnSpan(['lg' => 2])->schema([
                    Section::make('Desconto')->columns(2)->schema([
                        TextInput::make('title')->label('Nome (aparece no carrinho e no pedido)')->required()->columnSpanFull(),
                        Select::make('type')->label('Tipo')->options(Discount::TYPES)->required()->live()->default('percentage'),
                        TextInput::make('code')->label('Código do cupom')->placeholder('Em branco = desconto automático')
                            ->unique(ignoreRecord: true)->extraInputAttributes(['style' => 'text-transform:uppercase'])
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? Str::upper(trim($state)) : null)
                            ->suffixAction(Action::make('gerar')->icon(Heroicon::OutlinedSparkles)->tooltip('Gerar código')
                                ->action(fn (Set $set) => $set('code', Str::upper(Str::random(8))))),

                        TextInput::make('value')->label('Desconto (%)')->numeric()->minValue(1)->maxValue(100)->suffix('%')
                            ->visible($is('percentage', 'payment_method'))->required($is('percentage', 'payment_method')),
                        Money::input('value_money', 'Valor do desconto')->dehydrated(false)
                            ->visible($is('fixed_amount'))->required($is('fixed_amount')),

                        Select::make('rules.method')->label('Forma de pagamento')->options(['pix' => 'PIX', 'credit_card' => 'Cartão de crédito'])
                            ->visible($is('payment_method'))->required($is('payment_method')),

                        TextInput::make('rules.buy_qty')->label('Compre (quantidade)')->numeric()->minValue(1)->default(1)
                            ->visible($is('buy_x_get_y'))->required($is('buy_x_get_y')),
                        TextInput::make('rules.get_qty')->label('Leve com desconto (quantidade)')->numeric()->minValue(1)->default(1)
                            ->visible($is('buy_x_get_y'))->required($is('buy_x_get_y')),
                        TextInput::make('rules.get_percent')->label('Desconto nas peças "leve" (%)')->numeric()->default(100)->suffix('%')
                            ->helperText('100% = grátis. As peças mais baratas recebem o desconto.')
                            ->visible($is('buy_x_get_y'))->required($is('buy_x_get_y')),

                        Repeater::make('rules.tiers')->label('Faixas do desconto progressivo')->columnSpanFull()
                            ->schema([
                                TextInput::make('min_qty')->label('A partir de (peças)')->numeric()->minValue(2)->required(),
                                TextInput::make('percent')->label('Desconto (%)')->numeric()->minValue(1)->maxValue(90)->required()->suffix('%'),
                            ])->columns(2)->defaultItems(2)->addActionLabel('Adicionar faixa')
                            ->visible($is('tiered'))->required($is('tiered')),

                        Repeater::make('rules.items')->label('Produtos do combo')->columnSpanFull()
                            ->schema([
                                Select::make('product_id')->label('Produto')->options(fn () => Product::active()->orderBy('title')->pluck('title', 'id'))->searchable()->required(),
                                TextInput::make('qty')->label('Quantidade')->numeric()->minValue(1)->default(1)->required(),
                            ])->columns(2)->defaultItems(2)->addActionLabel('Adicionar produto ao combo')
                            ->visible($is('bundle'))->required($is('bundle')),
                        Select::make('rules.mode')->label('O combo sai por')->options(['price' => 'Preço fechado', 'percent' => 'Porcentagem de desconto'])->default('price')->live()
                            ->visible($is('bundle')),
                        Money::input('rules.price', 'Preço do combo')->visible(fn (Get $get) => $get('type') === 'bundle' && $get('rules.mode') !== 'percent'),
                        TextInput::make('rules.percent')->label('Desconto no combo (%)')->numeric()->suffix('%')
                            ->visible(fn (Get $get) => $get('type') === 'bundle' && $get('rules.mode') === 'percent'),
                    ]),

                    Section::make('Vale para')->columns(2)->visible($is('percentage', 'fixed_amount', 'buy_x_get_y', 'tiered'))->schema([
                        Select::make('applies_to')->label('Produtos')->options(['all' => 'Todos os produtos', 'categories' => 'Coleções específicas', 'products' => 'Produtos específicos'])
                            ->default('all')->live()->required(),
                        Select::make('target_categories')->label('Coleções')->multiple()->options(fn () => Category::orderBy('name')->pluck('name', 'id'))
                            ->dehydrated(false)->visible(fn (Get $get) => $get('applies_to') === 'categories')->required(fn (Get $get) => $get('applies_to') === 'categories'),
                        Select::make('target_products')->label('Produtos')->multiple()->searchable()->options(fn () => Product::orderBy('title')->pluck('title', 'id'))
                            ->dehydrated(false)->visible(fn (Get $get) => $get('applies_to') === 'products')->required(fn (Get $get) => $get('applies_to') === 'products'),
                    ]),

                    Section::make('Condições e limites')->columns(2)->schema([
                        Money::input('min_subtotal', 'Pedido mínimo'),
                        TextInput::make('min_quantity')->label('Quantidade mínima de peças')->numeric(),
                        TextInput::make('usage_limit')->label('Limite total de usos')->numeric()->helperText('Em branco = sem limite.'),
                        TextInput::make('usage_limit_per_customer')->label('Usos por cliente')->numeric()->helperText('1 = só uma vez por e-mail (ex.: cupom de primeira compra).'),
                        Toggle::make('combinable')->label('Acumula com outros descontos')->columnSpanFull()
                            ->helperText('Desligado: entre os não acumuláveis, vale só o de maior valor.'),
                    ]),
                ]),
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Ativo')->schema([
                        Toggle::make('is_active')->label('Ativo')->default(true),
                        DateTimePicker::make('starts_at')->label('Começa em')->seconds(false),
                        DateTimePicker::make('ends_at')->label('Termina em')->seconds(false)->helperText('Ideal para a Black Friday: programe início e fim.'),
                    ]),
                    Section::make('Uso')->visibleOn('edit')->schema([
                        TextInput::make('used_count')->label('Pedidos pagos com este desconto')->disabled()->dehydrated(false),
                    ]),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('Desconto')->weight('medium')->searchable()
                    ->description(fn (Discount $r) => $r->code ? 'Cupom: '.$r->code : 'Automático'),
                TextColumn::make('type')->label('Tipo')->formatStateUsing(fn ($state) => Discount::TYPES[$state] ?? $state),
                TextColumn::make('resumo')->label('Regra')->state(fn (Discount $r) => self::summary($r))->wrap(),
                TextColumn::make('used_count')->label('Usos')->formatStateUsing(fn ($state, Discount $r) => $state.($r->usage_limit ? ' / '.$r->usage_limit : ''))->alignCenter(),
                TextColumn::make('situacao')->label('Situação')->badge()->state(fn (Discount $r) => match (true) {
                    ! $r->is_active => 'Desativado',
                    $r->starts_at && $r->starts_at->isFuture() => 'Agendado',
                    $r->ends_at && $r->ends_at->isPast() => 'Encerrado',
                    $r->usage_limit && $r->used_count >= $r->usage_limit => 'Esgotado',
                    default => 'Ativo',
                })->color(fn ($state) => ['Ativo' => 'success', 'Agendado' => 'info', 'Encerrado' => 'gray', 'Esgotado' => 'warning', 'Desativado' => 'gray'][$state]),
                ToggleColumn::make('is_active')->label('Ativo'),
            ])
            ->filters([SelectFilter::make('type')->label('Tipo')->options(Discount::TYPES)])
            ->recordActions([
                EditAction::make(),
                ReplicateAction::make()->label('Duplicar')->excludeAttributes(['used_count'])
                    ->mutateRecordDataUsing(fn (array $data) => array_merge($data, ['code' => $data['code'] ? $data['code'].'2' : null, 'is_active' => false])),
                DeleteAction::make(),
            ]);
    }

    public static function summary(Discount $d): string
    {
        $r = $d->rules ?? [];
        $base = match ($d->type) {
            'percentage' => $d->value.'% de desconto',
            'fixed_amount' => money($d->value).' de desconto',
            'free_shipping' => 'Frete grátis',
            'payment_method' => $d->value.'% no '.(($r['method'] ?? '') === 'pix' ? 'PIX' : 'cartão'),
            'buy_x_get_y' => 'Compre '.($r['buy_qty'] ?? 1).', leve '.($r['get_qty'] ?? 1).' com '.($r['get_percent'] ?? 100).'%',
            'tiered' => collect($r['tiers'] ?? [])->map(fn ($t) => $t['min_qty'].'+ peças: '.$t['percent'].'%')->implode(' · '),
            'bundle' => 'Combo de '.count($r['items'] ?? []).' produtos '.(($r['mode'] ?? 'price') === 'percent' ? 'com '.($r['percent'] ?? 0).'%' : 'por '.money((int) ($r['price'] ?? 0))),
            default => '',
        };

        return $base.($d->min_subtotal ? ' · mínimo '.money($d->min_subtotal) : '');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDiscounts::route('/'),
            'create' => Pages\CreateDiscount::route('/create'),
            'edit' => Pages\EditDiscount::route('/{record}/edit'),
        ];
    }
}
