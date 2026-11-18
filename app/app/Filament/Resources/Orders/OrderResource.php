<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Services\Orders\OrderManager;
use App\Support\Br;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Vendas';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'pedido';

    protected static ?string $pluralModelLabel = 'pedidos';

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'customer_name', 'customer_email', 'customer_document', 'tracking_code'];
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Order::whereIn('status', ['paid', 'processing'])->count();

        return $n ? (string) $n : null; // pedidos pagos aguardando envio
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pagos aguardando envio';
    }

    public static function statusColor(string $s): string
    {
        return match ($s) {
            'paid', 'delivered' => 'success',
            'processing' => 'info',
            'shipped' => 'primary',
            'pending' => 'warning',
            'canceled', 'refunded' => 'danger',
            default => 'gray',
        };
    }

    public static function paymentColor(string $s): string
    {
        return match ($s) {
            'paid' => 'success',
            'pending', 'processing' => 'warning',
            'refused', 'failed', 'canceled' => 'danger',
            'refunded', 'chargeback', 'med' => 'danger',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('Pedido')->formatStateUsing(fn ($state) => '#'.$state)->searchable()->sortable()->weight('bold'),
                TextColumn::make('created_at')->label('Data')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('customer_name')->label('Cliente')->description(fn (Order $r) => $r->customer_email)
                    ->searchable(['customer_name', 'customer_email', 'customer_document']),
                TextColumn::make('total')->label('Total')->formatStateUsing(fn ($state) => money($state))->sortable()->alignEnd(),
                TextColumn::make('payment_method')->label('Pagamento')->formatStateUsing(fn ($state, Order $r) => $state === 'pix' ? 'PIX' : "Cartão {$r->installments}x"),
                TextColumn::make('payment_status')->label('Pgto.')->badge()
                    ->formatStateUsing(fn ($state) => Order::PAYMENT_STATUSES[$state] ?? $state)->color(fn ($state) => self::paymentColor($state)),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => Order::STATUSES[$state] ?? $state)->color(fn ($state) => self::statusColor($state)),
                TextColumn::make('utm_source')->label('Origem')->placeholder('—')->toggleable(),
                TextColumn::make('utm_campaign')->label('Campanha')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tracking_code')->label('Rastreio')->placeholder('—')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('risk_score')->label('Risco')->badge()->color(fn ($state) => $state >= 50 ? 'danger' : ($state >= 20 ? 'warning' : 'gray'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(Order::STATUSES)->multiple(),
                SelectFilter::make('payment_status')->label('Pagamento')->options(Order::PAYMENT_STATUSES)->multiple(),
                SelectFilter::make('payment_method')->label('Forma')->options(['pix' => 'PIX', 'credit_card' => 'Cartão']),
                SelectFilter::make('utm_source')->label('Origem (UTM)')
                    ->options(fn () => Order::whereNotNull('utm_source')->distinct()->orderBy('utm_source')->pluck('utm_source', 'utm_source')->all()),
                Filter::make('periodo')->label('Período')
                    ->schema([DatePicker::make('de')->label('De'), DatePicker::make('ate')->label('Até')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['de'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                        ->when($data['ate'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))),
            ])
            ->recordActions([ViewAction::make()->label('Abrir')])
            ->toolbarActions([
                BulkAction::make('csvFornecedor')
                    ->label('Exportar para o fornecedor (CSV)')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (Collection $records) => response()->streamDownload(
                        fn () => print (app(OrderManager::class)->supplierCsv($records)),
                        'pedidos-'.now()->format('Y-m-d-His').'.csv',
                        ['Content-Type' => 'text/csv; charset=UTF-8']
                    ))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make('Itens')->columnSpan(['lg' => 2])->schema([
                    ViewEntry::make('items')->hiddenLabel()->view('admin.orders.items'),
                ]),
                Section::make('Resumo')->columnSpan(1)->schema([
                    TextEntry::make('status')->label('Status')->badge()
                        ->formatStateUsing(fn ($state) => Order::STATUSES[$state] ?? $state)->color(fn ($state) => self::statusColor($state)),
                    TextEntry::make('payment_status')->label('Pagamento')->badge()
                        ->formatStateUsing(fn ($state) => Order::PAYMENT_STATUSES[$state] ?? $state)->color(fn ($state) => self::paymentColor($state)),
                    TextEntry::make('payment_method')->label('Forma')
                        ->formatStateUsing(fn ($state, Order $r) => $state === 'pix' ? 'PIX' : trim('Cartão '.$r->card_brand.' '.($r->card_last4 ? '•••• '.$r->card_last4 : '').' · '.$r->installments.'x')),
                    TextEntry::make('paid_at')->label('Pago em')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('total')->label('Total')->formatStateUsing(fn ($state) => money($state))->weight('bold')->size('lg'),
                    TextEntry::make('gateway_fee')->label('Taxa do gateway')->formatStateUsing(fn ($state) => $state !== null ? money($state) : '—'),
                    TextEntry::make('gateway_uuid')->label('Transação Nixfy')->copyable()->placeholder('—'),
                ]),
            ]),
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make('Cliente')->schema([
                    TextEntry::make('customer_name')->label('Nome'),
                    TextEntry::make('customer_email')->label('E-mail')->copyable(),
                    TextEntry::make('customer_phone')->label('Celular')->formatStateUsing(fn ($state) => Br::formatPhone($state))
                        ->url(fn (Order $r) => 'https://wa.me/55'.preg_replace('/\D/', '', $r->customer_phone), true)->color('primary'),
                    TextEntry::make('customer_document')->label('CPF')->formatStateUsing(fn ($state) => Br::formatCpf($state))->copyable(),
                ]),
                Section::make('Entrega')->schema([
                    TextEntry::make('shipping_address')->label('Endereço')->state(function (Order $r) {
                        $a = $r->shipping_address ?? [];

                        return trim(($a['street'] ?? '').', '.($a['number'] ?? '').(! empty($a['complement']) ? ' – '.$a['complement'] : ''))
                            ."\n".($a['neighborhood'] ?? '').' · '.($a['city'] ?? '').'/'.($a['state'] ?? '').' · CEP '.Br::formatCep($a['zip'] ?? '');
                    })->copyable(),
                    TextEntry::make('shipping_method')->label('Frete')->state(fn (Order $r) => $r->shipping_method.' · '.($r->shipping_total ? money($r->shipping_total) : 'Grátis')),
                    TextEntry::make('tracking_code')->label('Rastreio')->placeholder('Ainda não enviado')->url(fn (Order $r) => $r->tracking_url, true)->copyable(),
                ]),
                Section::make('Origem e antifraude')->schema([
                    TextEntry::make('utm_source')->label('Origem')->state(fn (Order $r) => trim(($r->utm_source ?: 'direto').' / '.($r->utm_medium ?: '—'))),
                    TextEntry::make('utm_campaign')->label('Campanha')->placeholder('—'),
                    TextEntry::make('utm_content')->label('Anúncio (utm_content)')->placeholder('—'),
                    TextEntry::make('risk_score')->label('Risco do cartão')->badge()
                        ->color(fn ($state) => $state >= 50 ? 'danger' : ($state >= 20 ? 'warning' : 'success'))
                        ->formatStateUsing(fn ($state, Order $r) => $state.'/100'.($r->risk_flags ? ' · '.implode(', ', $r->risk_flags) : '')),
                    TextEntry::make('purchase_event_sent_at')->label('Compra enviada às plataformas')->dateTime('d/m/Y H:i')->placeholder('Ainda não'),
                ]),
            ]),
            Section::make('Linha do tempo')->collapsible()->schema([
                ViewEntry::make('events')->hiddenLabel()->view('admin.orders.timeline'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // pedidos nascem no checkout
    }
}
