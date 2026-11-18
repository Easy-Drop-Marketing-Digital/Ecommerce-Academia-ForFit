<?php

namespace App\Filament\Resources\Brands;

use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Support\ImageField;
use App\Models\Brand;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/** Marcas (fabricantes): página /marcas/{slug}, filtro nas coleções, selo no card e marca no feed. */
class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'marca';

    protected static ?string $pluralModelLabel = 'marcas';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Marca')->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label('Nome')->required()->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
                TextInput::make('slug')->label('Endereço (URL)')->prefix(url('/marcas').'/')->required()->unique(ignoreRecord: true),
                ColorPicker::make('color')->label('Cor de destaque (página da marca)'),
                Toggle::make('is_active')->label('Ativa')->default(true)->inline(false),
                RichEditor::make('description')->label('Descrição (topo da página da marca)')->columnSpanFull()
                    ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList']]),
                ImageField::make('logo', 'Logo da marca (fundo transparente)', 'brands', 400),
                ImageField::make('banner_image', 'Banner do topo da página da marca (opcional)', 'brands', 1920),
            ]),
            Section::make('SEO')->collapsed()->columns(1)->columnSpanFull()->schema([
                TextInput::make('seo_title')->label('Título para o Google')->maxLength(70),
                Textarea::make('seo_description')->label('Descrição para o Google')->maxLength(160)->rows(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('position')
            ->defaultSort('position')
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->withCount('products'))
            ->columns([
                ImageColumn::make('img')->label('')->state(fn (Brand $r) => $r->logo ? img_url($r->logo, 160) : null)->imageHeight(40),
                TextColumn::make('name')->label('Marca')->searchable()->weight('medium')->description(fn (Brand $r) => '/marcas/'.$r->slug),
                TextColumn::make('products_count')->label('Produtos')->alignCenter(),
                ToggleColumn::make('is_active')->label('Ativa'),
            ])
            ->headerActions([CreateAction::make()->label('Nova marca')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBrands::route('/'),
            'edit' => EditBrand::route('/{record}/edit'),
        ];
    }
}
