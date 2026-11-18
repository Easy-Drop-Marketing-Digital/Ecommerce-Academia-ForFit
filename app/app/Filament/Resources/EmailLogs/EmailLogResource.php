<?php

namespace App\Filament\Resources\EmailLogs;

use App\Filament\Resources\EmailLogs\Pages\ManageEmailLogs;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\EmailLog;
use App\Models\Order;
use App\Services\Mail\EmailService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Histórico de todos os e-mails que a loja enviou (somente leitura) + envio de teste. */
class EmailLogResource extends Resource
{
    protected static ?string $model = EmailLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'E-mails enviados';

    protected static ?string $modelLabel = 'e-mail';

    protected static ?string $pluralModelLabel = 'e-mails enviados';

    public const STATUSES = ['sent' => 'Enviado', 'queued' => 'Enviando', 'failed' => 'Falhou'];

    public static function getNavigationBadge(): ?string
    {
        $n = EmailLog::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Falhas nas últimas 24 horas';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i')->sortable()
                    ->description(fn (EmailLog $record) => $record->created_at?->diffForHumans()),
                TextColumn::make('template')->label('E-mail')
                    ->formatStateUsing(fn (string $state) => EmailService::TEMPLATES[$state] ?? $state)
                    ->description(fn (EmailLog $record) => mb_strimwidth((string) $record->subject, 0, 70, '…')),
                TextColumn::make('to')->label('Para')->searchable()->copyable(),
                TextColumn::make('status')->label('Situação')->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'sent' => 'success', 'failed' => 'danger', default => 'warning' })
                    ->tooltip(fn (EmailLog $record) => $record->error),
                TextColumn::make('subject_model_id')->label('Pedido')
                    ->formatStateUsing(fn (EmailLog $record) => $record->subject_model_type === (new Order)->getMorphClass()
                        ? '#'.(Order::whereKey($record->subject_model_id)->value('number') ?? $record->subject_model_id) : '—')
                    ->url(fn (EmailLog $record) => $record->subject_model_type === (new Order)->getMorphClass()
                        ? OrderResource::getUrl('view', ['record' => $record->subject_model_id]) : null),
            ])
            ->filters([
                SelectFilter::make('template')->label('E-mail')->options(EmailService::TEMPLATES),
                SelectFilter::make('status')->label('Situação')->options(self::STATUSES),
                Filter::make('today')->label('Hoje')->query(fn (Builder $query) => $query->where('created_at', '>=', today())),
            ])
            ->emptyStateHeading('Nenhum e-mail enviado ainda')
            ->emptyStateDescription('Assim que houver pedidos, cadastros ou carrinhos abandonados, cada e-mail aparece aqui.');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmailLogs::route('/')];
    }
}
