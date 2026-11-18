<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\ImagesRelationManager;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Filament\Support\Money;
use App\Models\Category;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use UnitEnum;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'produto';

    protected static ?string $pluralModelLabel = 'produtos';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                Grid::make(1)->columnSpan(['lg' => 2])->schema([
                    Section::make('Produto')->schema([
                        TextInput::make('title')->label('Título')->required()->maxLength(255)->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, ?string $state, string $operation) {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')->label('Endereço (URL)')->prefix(url('/products').'/')->required()
                            ->unique(ignoreRecord: true)->helperText('Mudar o endereço de um produto já anunciado quebra os links dos anúncios.'),
                        RichEditor::make('description')->label('Descrição')
                            ->toolbarButtons([['bold', 'italic', 'underline', 'strike', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList'], ['attachFiles'], ['undo', 'redo']])
                            ->fileAttachmentsDisk('public')->fileAttachmentsDirectory('descricoes'),
                    ]),

                    Section::make('Opções do produto')->description('Ex.: Cor (Preto, Branco) e Tamanho (P, M, G). Depois use "Gerar variações" na aba de variações abaixo.')
                        ->visibleOn('edit')->collapsible()->schema([
                            Repeater::make('options')->relationship()->hiddenLabel()->orderColumn('position')
                                ->schema([
                                    TextInput::make('name')->label('Nome da opção')->required()->datalist(['Cor', 'Tamanho', 'Kit']),
                                    Select::make('display_type')->label('Exibição')->options(['button' => 'Botões', 'swatch' => 'Cores (botões)', 'select' => 'Lista'])->default('button'),
                                    TagsInput::make('values')->label('Valores')->required()->placeholder('Digite e tecle Enter')->columnSpanFull(),
                                ])->columns(2)->addActionLabel('Adicionar opção')->maxItems(3)->defaultItems(0),
                        ]),

                    Section::make('Preço e estoque iniciais')->visibleOn('create')->columns(2)
                        ->description('Cria a primeira variação. Para cores e tamanhos, salve e cadastre as opções em seguida.')
                        ->schema([
                            Money::input('initial_price', 'Preço de venda')->required()->dehydrated(false),
                            Money::input('initial_compare', 'Preço "de" (riscado)')->dehydrated(false),
                            TextInput::make('initial_stock')->label('Estoque')->numeric()->default(1000)->dehydrated(false),
                            TextInput::make('initial_sku')->label('SKU')->dehydrated(false),
                        ]),

                    Section::make('SEO e Google')->collapsed()->columns(2)->schema([
                        TextInput::make('seo_title')->label('Título para o Google')->maxLength(70)->helperText('Até 70 caracteres. Em branco usa o título do produto.'),
                        TextInput::make('google_category')->label('Categoria Google (feed)')->placeholder('Health & Beauty > Health Care > Fitness & Nutrition'),
                        Textarea::make('seo_description')->label('Descrição para o Google')->maxLength(160)->rows(2)->columnSpanFull(),
                    ]),
                ]),

                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Status')->schema([
                        Select::make('status')->label('Situação')->options(['active' => 'Ativo (à venda)', 'draft' => 'Rascunho (oculto)', 'archived' => 'Arquivado'])
                            ->default('active')->required(),
                        Select::make('categories')->label('Coleções')->relationship('categories', 'name')->multiple()->preload()->searchable(),
                        TagsInput::make('tags')->label('Tags (busca)'),
                        Select::make('brand_id')->label('Marca')->relationship('brand', 'name')->preload()->searchable(),
                        TextInput::make('vendor')->label('Fornecedor')->placeholder('Ex.: distribuidor de onde vem o estoque'),
                    ]),
                    Section::make('Estoque')->schema([
                        Toggle::make('track_stock')->label('Controlar estoque')->default(true),
                        Toggle::make('allow_backorder')->label('Vender mesmo sem estoque')->helperText('Venda sob encomenda: aceita pedido mesmo com estoque zerado.'),
                    ]),
                    Section::make('Desempenho')->visibleOn('edit')->schema([
                        TextInput::make('sales_count')->label('Unidades vendidas')->disabled()->dehydrated(false),
                        TextInput::make('rating_summary')->label('Avaliações')->disabled()->dehydrated(false)
                            ->formatStateUsing(fn (?Product $record) => $record ? number_format((float) $record->rating_avg, 1, ',', '').' ★ ('.$record->rating_count.')' : null),
                    ]),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['images' => fn ($i) => $i->orderBy('position')->limit(1), 'categories:id,name', 'brand:id,name'])
                ->withSum(['variants as stock_total' => fn ($v) => $v->where('is_active', true)], 'stock')
                ->withCount('variants'))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                ImageColumn::make('thumb')->label('')->state(fn (Product $r) => $r->images->first() ? img_url($r->images->first()->path, 120) : null)->imageHeight(56)->width(45),
                TextColumn::make('title')->label('Produto')->searchable()->sortable()->wrap()->limit(70)
                    ->description(fn (Product $r) => $r->variants_count.' '.($r->variants_count === 1 ? 'variação' : 'variações')),
                TextColumn::make('status')->label('Situação')->badge()
                    ->formatStateUsing(fn ($state) => ['active' => 'Ativo', 'draft' => 'Rascunho', 'archived' => 'Arquivado'][$state] ?? $state)
                    ->color(fn ($state) => ['active' => 'success', 'draft' => 'warning', 'archived' => 'gray'][$state] ?? 'gray'),
                TextColumn::make('min_price')->label('Preço')->formatStateUsing(fn ($state) => money($state))->sortable()
                    ->description(fn (Product $r) => $r->max_compare_price ? 'de '.money($r->max_compare_price) : null),
                TextColumn::make('stock_total')->label('Estoque')->sortable()->alignCenter()
                    ->color(fn ($state) => (int) $state <= 0 ? 'danger' : ((int) $state < 20 ? 'warning' : null)),
                TextColumn::make('sales_count')->label('Vendidos')->sortable()->alignCenter(),
                TextColumn::make('brand.name')->label('Marca')->badge()->color('gray')->sortable(),
                TextColumn::make('categories.name')->label('Coleções')->badge()->limitList(2)->toggleable(),
                TextColumn::make('updated_at')->label('Atualizado')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Situação')->options(['active' => 'Ativo', 'draft' => 'Rascunho', 'archived' => 'Arquivado']),
                SelectFilter::make('brand_id')->label('Marca')->relationship('brand', 'name')->preload(),
                SelectFilter::make('categories')->label('Coleção')->relationship('categories', 'name')->preload(),
                Filter::make('sem_estoque')->label('Com variação esgotada')
                    ->query(fn (Builder $query) => $query->whereHas('variants', fn ($v) => $v->where('is_active', true)->where('stock', '<=', 0))),
                Filter::make('estoque_baixo')->label('Estoque baixo (< 10 em alguma variação)')
                    ->query(fn (Builder $query) => $query->whereHas('variants', fn ($v) => $v->where('is_active', true)->where('stock', '<', 10))),
                TrashedFilter::make()->label('Excluídos'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('ativar')->label('Ativar')->icon(Heroicon::OutlinedEye)
                        ->action(fn (Collection $r) => self::bulkUpdate($r, ['status' => 'active'])),
                    BulkAction::make('rascunho')->label('Mover para rascunho')->icon(Heroicon::OutlinedEyeSlash)
                        ->action(fn (Collection $r) => self::bulkUpdate($r, ['status' => 'draft'])),
                    BulkAction::make('colecao')->label('Adicionar a uma coleção')->icon(Heroicon::OutlinedFolderPlus)
                        ->schema([Select::make('category_id')->label('Coleção')->options(Category::orderBy('name')->pluck('name', 'id'))->required()])
                        ->action(function (Collection $records, array $data) {
                            $cat = Category::find($data['category_id']);
                            $max = (int) $cat->products()->max('category_product.position');
                            foreach ($records as $i => $p) {
                                $cat->products()->syncWithoutDetaching([$p->id => ['position' => $max + $i + 1]]);
                            }
                            Cache::forget('home.sections');
                            Notification::make()->title('Produtos adicionados à coleção '.$cat->name)->success()->send();
                        }),
                    BulkAction::make('estoque')->label('Definir estoque de todas as variações')->icon(Heroicon::OutlinedCube)
                        ->schema([TextInput::make('stock')->label('Estoque')->numeric()->required()->default(1000)])
                        ->action(function (Collection $records, array $data) {
                            foreach ($records as $p) {
                                foreach ($p->variants as $v) {
                                    $v->adjustStock((int) $data['stock'] - $v->stock, 'manual', null, auth()->id());
                                }
                            }
                            Notification::make()->title('Estoque atualizado')->success()->send();
                        }),
                    BulkAction::make('promo')->label('Aplicar promoção (% sobre o preço)')->icon(Heroicon::OutlinedReceiptPercent)
                        ->schema([
                            TextInput::make('percent')->label('Desconto (%)')->numeric()->minValue(1)->maxValue(90)->required(),
                            Toggle::make('keep_compare')->label('Mostrar o preço atual riscado (preço "de")')->default(true),
                        ])
                        ->action(function (Collection $records, array $data) {
                            foreach ($records as $p) {
                                foreach ($p->variants as $v) {
                                    $new = (int) round($v->price * (100 - (float) $data['percent']) / 100);
                                    $v->update([
                                        'compare_at_price' => $data['keep_compare'] ? max($v->compare_at_price ?? 0, $v->price) : $v->compare_at_price,
                                        'price' => max(1, $new),
                                    ]);
                                }
                            }
                            Notification::make()->title('Promoção aplicada em '.$records->count().' produto(s)')->success()->send();
                        }),
                    DeleteBulkAction::make()->label('Excluir'),
                ]),
            ]);
    }

    private static function bulkUpdate(Collection $records, array $data): void
    {
        Product::whereIn('id', $records->pluck('id'))->update($data);
        Cache::forget('home.sections');
        Notification::make()->title('Produtos atualizados')->success()->send();
    }

    public static function getRelations(): array
    {
        return [ImagesRelationManager::class, VariantsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([\Illuminate\Database\Eloquent\SoftDeletingScope::class]);
    }
}
