<?php

namespace App\Filament\Resources\Reviews;

use App\Models\Review;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'avaliação';

    protected static ?string $pluralModelLabel = 'avaliações';

    public static function getNavigationBadge(): ?string
    {
        $n = Review::where('status', 'pending')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Aguardando moderação';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Avaliação')->columns(2)->columnSpanFull()->schema([
                Select::make('product_id')->label('Produto')->relationship('product', 'title')->searchable()->required(),
                Select::make('status')->label('Situação')->options(Review::STATUSES)->required()->default('approved'),
                TextInput::make('author')->label('Nome exibido')->required(),
                Select::make('rating')->label('Nota')->options([5 => '★★★★★ (5)', 4 => '★★★★ (4)', 3 => '★★★ (3)', 2 => '★★ (2)', 1 => '★ (1)'])->required(),
                TextInput::make('title')->label('Título')->columnSpanFull(),
                Textarea::make('body')->label('Comentário')->rows(4)->columnSpanFull(),
                Toggle::make('featured')->label('Destacar no topo do produto'),
                Toggle::make('verified')->label('Compra verificada')->disabled()->dehydrated(false),
            ]),
            Section::make('Resposta da loja (pública)')->columnSpanFull()->schema([
                Textarea::make('reply')->hiddenLabel()->rows(3)->placeholder('Ex.: Que bom que você gostou, Maria! 💛'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $set = fn (string $status) => fn (Collection $records) => $records->each->update(['status' => $status]);

        return $table
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with('product:id,title'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('rating')->label('Nota')->formatStateUsing(fn ($state) => str_repeat('★', $state).str_repeat('☆', 5 - $state))
                    ->color(fn ($state) => $state >= 4 ? 'warning' : 'danger')->sortable(),
                TextColumn::make('body')->label('Comentário')->wrap()->limit(140)->description(fn (Review $r) => $r->author.($r->verified ? ' · compra verificada' : ''))
                    ->searchable(['body', 'author', 'title']),
                TextColumn::make('product.title')->label('Produto')->limit(40)->searchable(),
                IconColumn::make('media')->label('Fotos')->state(fn (Review $r) => ! empty($r->media))->boolean()->toggleable(),
                TextColumn::make('status')->label('Situação')->badge()->formatStateUsing(fn ($state) => Review::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['approved' => 'success', 'pending' => 'warning', 'hidden' => 'gray'][$state] ?? 'gray'),
                TextColumn::make('source')->label('Origem')->formatStateUsing(fn ($state) => ['import' => 'Loja antiga', 'customer' => 'Cliente', 'admin' => 'Admin'][$state] ?? $state)->toggleable(),
                IconColumn::make('featured')->label('Destaque')->boolean()->toggleable(),
                TextColumn::make('created_at')->label('Data')->date('d/m/Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Situação')->options(Review::STATUSES),
                SelectFilter::make('rating')->label('Nota')->options([5 => '5 estrelas', 4 => '4 estrelas', 3 => '3 estrelas', 2 => '2 estrelas', 1 => '1 estrela'])->multiple(),
                SelectFilter::make('source')->label('Origem')->options(['import' => 'Loja antiga', 'customer' => 'Cliente', 'admin' => 'Admin']),
                SelectFilter::make('product_id')->label('Produto')->relationship('product', 'title')->searchable(),
                TernaryFilter::make('verified')->label('Compra verificada'),
            ])
            ->recordActions([
                Action::make('aprovar')->label('Publicar')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (Review $r) => $r->status !== 'approved')->action(fn (Review $r) => $r->update(['status' => 'approved'])),
                Action::make('ocultar')->label('Ocultar')->icon(Heroicon::OutlinedEyeSlash)->color('gray')
                    ->visible(fn (Review $r) => $r->status === 'approved')->action(fn (Review $r) => $r->update(['status' => 'hidden'])),
                EditAction::make()->label('Editar / responder')
                    ->mutateDataUsing(fn (array $data, Review $record) => $data + (filled($data['reply'] ?? null) && $data['reply'] !== $record->reply ? ['replied_at' => now()] : [])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publicar')->label('Publicar')->icon(Heroicon::OutlinedCheck)->action($set('approved')),
                    BulkAction::make('ocultar')->label('Ocultar')->icon(Heroicon::OutlinedEyeSlash)->action($set('hidden')),
                    DeleteBulkAction::make()->label('Excluir'),
                ]),
            ])
            ->headerActions([CreateAction::make()->label('Nova avaliação')->mutateDataUsing(fn (array $data) => $data + ['source' => 'admin'])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReviews::route('/'),
            'edit' => Pages\EditReview::route('/{record}/edit'),
        ];
    }
}
