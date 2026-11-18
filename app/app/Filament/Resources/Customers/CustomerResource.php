<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Models\Customer;
use App\Support\Br;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Vendas';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'cliente';

    protected static ?string $pluralModelLabel = 'clientes';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'document', 'phone'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                Section::make('Dados')->columnSpan(['lg' => 2])->columns(2)->schema([
                    TextInput::make('name')->label('Nome')->required(),
                    TextInput::make('email')->label('E-mail')->email()->required()->unique(ignoreRecord: true),
                    TextInput::make('phone')->label('Celular')->tel(),
                    TextInput::make('document')->label('CPF')->formatStateUsing(fn ($state) => $state ? Br::formatCpf($state) : null)
                        ->dehydrateStateUsing(fn ($state) => Br::digits($state) ?: null),
                    TagsInput::make('tags')->label('Etiquetas')->placeholder('Ex.: VIP, troca')->columnSpanFull(),
                    Textarea::make('notes')->label('Notas internas')->rows(3)->columnSpanFull(),
                    Toggle::make('accepts_marketing')->label('Aceita receber ofertas por e-mail'),
                ]),
                Section::make('Resumo')->columnSpan(1)->schema([
                    Text::make(fn (?Customer $record) => 'Pedidos pagos: '.($record->orders_count ?? 0)),
                    Text::make(fn (?Customer $record) => 'Total gasto: '.money($record->total_spent ?? 0)),
                    Text::make(fn (?Customer $record) => 'Ticket médio: '.money(($record?->orders_count ?? 0) ? intdiv($record->total_spent, $record->orders_count) : 0)),
                    Text::make(fn (?Customer $record) => 'Última compra: '.($record?->last_order_at?->format('d/m/Y') ?? '—')),
                    Text::make(fn (?Customer $record) => 'Conta com senha: '.($record?->password ? 'sim' : 'não (comprou como convidada)')),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Nome')->description(fn (Customer $r) => $r->email)->searchable(['name', 'email', 'document', 'phone']),
                TextColumn::make('phone')->label('Celular')->formatStateUsing(fn ($state) => $state ? Br::formatPhone($state) : '—')->toggleable(),
                TextColumn::make('orders_count')->label('Pedidos')->sortable()->alignCenter(),
                TextColumn::make('total_spent')->label('Total gasto')->formatStateUsing(fn ($state) => money($state))->sortable()->alignEnd(),
                TextColumn::make('last_order_at')->label('Última compra')->date('d/m/Y')->sortable()->placeholder('—'),
                IconColumn::make('accepts_marketing')->label('E-mail mkt')->boolean()->toggleable(),
                TextColumn::make('created_at')->label('Cadastro')->date('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('compradoras')->label('Já compraram')->query(fn (Builder $query) => $query->where('orders_count', '>', 0)),
                Filter::make('recorrentes')->label('Compraram 2+ vezes')->query(fn (Builder $query) => $query->where('orders_count', '>=', 2)),
                TernaryFilter::make('accepts_marketing')->label('Aceita e-mail marketing'),
            ])
            ->recordActions([EditAction::make()->label('Abrir')])
            ->toolbarActions([
                BulkAction::make('exportar')->label('Exportar (CSV para público personalizado)')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (Collection $records) => response()->streamDownload(function () use ($records) {
                        $out = fopen('php://output', 'w');
                        fwrite($out, "\xEF\xBB\xBF");
                        // Cabeçalhos no padrão de "Público personalizado" do Meta (email, phone, fn, ln, ct, st, zip, country)
                        fputcsv($out, ['email', 'phone', 'fn', 'ln', 'country', 'value']);
                        foreach ($records as $c) {
                            $parts = preg_split('/\s+/', trim($c->name));
                            fputcsv($out, [$c->email, $c->phone ? '55'.Br::digits($c->phone) : '', $parts[0] ?? '', count($parts) > 1 ? end($parts) : '', 'BR', number_format($c->total_spent / 100, 2, '.', '')]);
                        }
                        fclose($out);
                    }, 'clientes-'.now()->format('Y-m-d').'.csv')),
            ]);
    }

    public static function getRelations(): array
    {
        return [OrdersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
