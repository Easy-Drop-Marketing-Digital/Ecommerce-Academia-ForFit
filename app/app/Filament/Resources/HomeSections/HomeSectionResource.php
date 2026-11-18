<?php

namespace App\Filament\Resources\HomeSections;

use App\Filament\Resources\Banners\BannerResource;
use App\Models\Category;
use App\Models\HomeSection;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

class HomeSectionResource extends Resource
{
    protected static ?string $model = HomeSection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|UnitEnum|null $navigationGroup = 'Loja online';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Página inicial';

    protected static ?string $modelLabel = 'seção da home';

    protected static ?string $pluralModelLabel = 'seções da página inicial';

    public const TYPES = [
        'slideshow' => 'Banner / carrossel de banners',
        'collection_list' => 'Carrossel de coleções',
        'product_list' => 'Vitrine de produtos de uma coleção',
        'benefits' => 'Faixa de benefícios (frete, PIX, parcelas, retirada)',
        'brand_list' => 'Compre por marca (cartões das marcas)',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')->label('Tipo de seção')->options(self::TYPES)->required()->live(),
            TextInput::make('title')->label('Título exibido (opcional)'),

            Select::make('settings.placement')->label('Banners de qual posição')->options(fn () => BannerResource::placements())
                ->visible(fn (Get $get) => $get('type') === 'slideshow')->required(fn (Get $get) => $get('type') === 'slideshow')
                ->helperText('Cadastre/edite as imagens em Marketing → Banners.'),
            TextInput::make('settings.autoplay')->label('Trocar banner a cada (milissegundos)')->numeric()->minValue(2500)->placeholder('6000 = 6 segundos; vazio = não troca sozinho')
                ->visible(fn (Get $get) => $get('type') === 'slideshow'),

            Select::make('settings.category_ids')->label('Coleções (na ordem)')->multiple()->options(fn () => Category::orderBy('name')->pluck('name', 'id'))
                ->visible(fn (Get $get) => $get('type') === 'collection_list')->required(fn (Get $get) => $get('type') === 'collection_list')->columnSpanFull(),

            Select::make('settings.category_id')->label('Produtos da coleção')->options(fn () => Category::orderBy('name')->pluck('name', 'id'))
                ->visible(fn (Get $get) => $get('type') === 'product_list')->required(fn (Get $get) => $get('type') === 'product_list')
                ->helperText('A ordem dos produtos é a da coleção (Catálogo → Coleções → arraste).'),
            TextInput::make('settings.limit')->label('Quantidade de produtos')->numeric()->default(12)
                ->visible(fn (Get $get) => $get('type') === 'product_list'),
            TextInput::make('settings.view_all_label')->label('Texto do link "ver todos" (vazio = sem link)')->placeholder('Ver todos')
                ->visible(fn (Get $get) => $get('type') === 'product_list'),

            Toggle::make('is_active')->label('Ativa')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $forget = fn () => Cache::forget('home.sections');

        return $table
            ->reorderable('position')
            ->defaultSort('position')
            ->paginated(false)
            ->description('Arraste para mudar a ordem das seções na página inicial.')
            ->columns([
                TextColumn::make('type')->label('Seção')->formatStateUsing(fn ($state) => self::TYPES[$state] ?? $state)->weight('medium'),
                TextColumn::make('detalhe')->label('Conteúdo')->state(function (HomeSection $r) {
                    $s = $r->settings ?? [];

                    return match ($r->type) {
                        'slideshow' => 'Banners: '.(BannerResource::placements()[$s['placement'] ?? ''] ?? ($s['placement'] ?? '—')),
                        'collection_list' => Category::whereIn('id', $s['category_ids'] ?? [])->pluck('name')->implode(', '),
                        'product_list' => 'Coleção: '.(Category::find($s['category_id'] ?? 0)?->name ?? '—').' · '.($s['limit'] ?? 12).' produtos',
                        default => '—',
                    };
                })->wrap(),
                TextColumn::make('title')->label('Título')->placeholder('—'),
                ToggleColumn::make('is_active')->label('Ativa')->afterStateUpdated($forget),
            ])
            ->headerActions([CreateAction::make()->label('Nova seção')->after($forget)])
            ->recordActions([EditAction::make()->after($forget), DeleteAction::make()->after($forget)])
            ->reorderRecordsTriggerAction(fn ($action) => $action->after($forget));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageHomeSections::route('/')];
    }
}
