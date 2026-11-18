<?php

namespace App\Filament\Resources\Pages;

use App\Models\Page;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Loja online';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'página';

    protected static ?string $pluralModelLabel = 'páginas e políticas';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('title')->label('Título')->required(),
                TextInput::make('slug')->label('Endereço')->required()->alphaDash()->unique(ignoreRecord: true)
                    ->helperText('Políticas usam /policies/<código>, demais páginas /pages/<código>.'),
                Textarea::make('content')->label('Conteúdo (HTML)')->rows(28)->columnSpanFull()->extraInputAttributes(['style' => 'font-family: monospace; font-size: 13px'])
                    ->helperText('Variáveis preenchidas pelas Configurações: [[store.name]], [[store.email]], [[store.whatsapp]], [[store.cnpj]], [[store.address]], [[store.hours]], [[delivery.transit_days]], [[delivery.processing_days]], [[nixfy.max_installments]], [[pix.validity]], [[shipping.table]]. Trecho opcional: <x-if key="store.cnpj">...</x-if>.'),
                TextInput::make('seo_title')->label('Título para o Google'),
                Textarea::make('seo_description')->label('Descrição para o Google')->rows(2),
                Toggle::make('is_active')->label('Publicada')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')->label('Página')->weight('medium')->searchable(),
                TextColumn::make('endereco')->label('Endereço')->state(fn (Page $r) => parse_url($r->url(), PHP_URL_PATH))->color('gray'),
                TextColumn::make('updated_at')->label('Atualizada')->since(),
                ToggleColumn::make('is_active')->label('Publicada'),
            ])
            ->headerActions([CreateAction::make()->label('Nova página')])
            ->recordActions([
                Action::make('ver')->label('Ver')->icon(Heroicon::OutlinedEye)->url(fn (Page $r) => $r->url(), true),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}
