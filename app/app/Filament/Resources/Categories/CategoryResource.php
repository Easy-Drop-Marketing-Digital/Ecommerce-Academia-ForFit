<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\RelationManagers\ProductsRelationManager;
use App\Filament\Support\ImageField;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
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

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'coleção';

    protected static ?string $pluralModelLabel = 'coleções';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Coleção')->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label('Nome')->required()->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
                TextInput::make('slug')->label('Endereço (URL)')->prefix(url('/collections').'/')->required()->unique(ignoreRecord: true),
                Select::make('parent_id')->label('Coleção pai (opcional)')->relationship('parent', 'name')->searchable()->preload(),
                Select::make('sort_order')->label('Ordem padrão dos produtos')->default('manual')->options([
                    'manual' => 'Manual (arrastar na lista de produtos)', 'best_selling' => 'Mais vendidos',
                    'price_asc' => 'Menor preço', 'price_desc' => 'Maior preço', 'newest' => 'Mais recentes',
                ]),
                RichEditor::make('description')->label('Descrição (aparece no topo da coleção)')->columnSpanFull()
                    ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList']]),
                ImageField::make('image', 'Imagem da coleção (carrossel da home)', 'collections', 600),
                ImageField::make('banner_image', 'Banner do topo da coleção (opcional)', 'collections', 1920),
                Toggle::make('is_active')->label('Ativa')->default(true),
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
                ImageColumn::make('img')->label('')->state(fn (Category $r) => $r->image ? img_url($r->image, 160) : null)->imageHeight(48),
                TextColumn::make('name')->label('Coleção')->searchable()->weight('medium')->description(fn (Category $r) => '/collections/'.$r->slug),
                TextColumn::make('products_count')->label('Produtos')->alignCenter(),
                ToggleColumn::make('is_active')->label('Ativa'),
            ])
            ->headerActions([CreateAction::make()->label('Nova coleção')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ProductsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
