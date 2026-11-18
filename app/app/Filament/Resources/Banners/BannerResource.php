<?php

namespace App\Filament\Resources\Banners;

use App\Filament\Support\ImageField;
use App\Models\Banner;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'banner';

    protected static ?string $pluralModelLabel = 'banners';

    /** Posições conhecidas (as seções "slideshow" da home apontam para uma delas) */
    public static function placements(): array
    {
        $known = [
            'home_hero' => 'Home — banner principal (rotativo)',
            'home_black_friday' => 'Home — banner do meio',
            'home_about' => 'Home — banner final (Sobre nós)',
        ];
        $used = Banner::distinct()->pluck('placement')->mapWithKeys(fn ($p) => [$p => $known[$p] ?? $p])->all();

        return $known + $used;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('placement')->label('Onde aparece')->options(fn () => self::placements())->required()
                ->createOptionForm([TextInput::make('placement')->label('Código da nova posição (ex.: home_natal)')->alphaDash()->required()])
                ->createOptionUsing(fn (array $data) => $data['placement']),
            TextInput::make('title')->label('Nome interno / texto alternativo')->required(),
            ImageField::make('image_desktop', 'Imagem para computador (ideal 1920×960)', 'banners', 1920)->required(),
            ImageField::make('image_mobile', 'Imagem para celular (ideal 828×1200, vertical)', 'banners', 828)
                ->helperText('Em branco: usa a imagem de computador.'),
            TextInput::make('link')->label('Link ao clicar')->placeholder('/collections/black-friday')->columnSpanFull(),
            DateTimePicker::make('starts_at')->label('Começa a aparecer em')->seconds(false)->helperText('Em branco: imediatamente.'),
            DateTimePicker::make('ends_at')->label('Para de aparecer em')->seconds(false)->helperText('Ideal para campanhas (Black Friday, Natal).'),
            Toggle::make('is_active')->label('Ativo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('position')
            ->defaultSort('position')
            ->defaultGroup('placement')
            ->columns([
                ImageColumn::make('desk')->label('Computador')->state(fn (Banner $r) => $r->imageUrl('image_desktop'))->imageHeight(60),
                ImageColumn::make('mob')->label('Celular')->state(fn (Banner $r) => $r->image_mobile ? $r->imageUrl('image_mobile') : null)->imageHeight(60),
                TextColumn::make('title')->label('Nome')->description(fn (Banner $r) => $r->link),
                TextColumn::make('janela')->label('Agendamento')->state(function (Banner $r) {
                    if (! $r->starts_at && ! $r->ends_at) {
                        return 'Sempre';
                    }

                    return ($r->starts_at?->format('d/m H:i') ?? 'agora').' → '.($r->ends_at?->format('d/m H:i') ?? 'sem fim');
                })->badge()->color(fn (Banner $r) => $r->ends_at && $r->ends_at->isPast() ? 'danger' : ($r->starts_at && $r->starts_at->isFuture() ? 'warning' : 'success')),
                TextColumn::make('clicks')->label('Cliques')->alignCenter()->toggleable(),
                ToggleColumn::make('is_active')->label('Ativo')->afterStateUpdated(fn () => Cache::forget('home.sections')),
            ])
            ->filters([SelectFilter::make('placement')->label('Posição')->options(fn () => self::placements())])
            ->headerActions([CreateAction::make()->label('Novo banner')->after(fn () => Cache::forget('home.sections'))])
            ->recordActions([
                EditAction::make()->after(fn () => Cache::forget('home.sections')),
                DeleteAction::make()->after(fn () => Cache::forget('home.sections')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageBanners::route('/')];
    }
}
