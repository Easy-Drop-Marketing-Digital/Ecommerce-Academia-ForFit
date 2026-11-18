<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\ProductImage;
use App\Support\ImageOptimizer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Fotos';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('alt')->label('Texto alternativo (acessibilidade e Google)')->maxLength(250),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('position')
            ->defaultSort('position')
            ->paginated(false)
            ->description('Arraste para reordenar. A primeira foto é a capa; a segunda aparece ao passar o mouse na vitrine.')
            ->columns([
                ImageColumn::make('preview')->label('Foto')->state(fn (ProductImage $r) => img_url($r->path, 240))->imageHeight(90),
                TextColumn::make('alt')->label('Texto alternativo')->placeholder('—')->wrap(),
                TextColumn::make('position')->label('Ordem')->alignCenter(),
            ])
            ->headerActions([
                Action::make('upload')->label('Enviar fotos')->icon(Heroicon::OutlinedArrowUpTray)
                    ->schema([
                        FileUpload::make('files')->label('Fotos (JPG, PNG ou WebP)')->multiple()->image()->disk('public')
                            ->directory('products/tmp')->maxSize(8192)->reorderable()->required(),
                    ])
                    ->action(function (array $data) {
                        $product = $this->getOwnerRecord();
                        $pos = (int) $product->images()->max('position');
                        foreach ($data['files'] as $path) {
                            // WebP em 5 tamanhos (200–1200px) na pasta do produto: o celular baixa só o necessário
                            $disk = Storage::disk('public');
                            $image = $product->images()->create(['path' => $path, 'alt' => $product->title, 'position' => ++$pos]);
                            $set = ImageOptimizer::responsive($disk->get($path), "products/{$product->id}/{$image->id}");
                            if ($set) {
                                $image->update($set);
                                $disk->delete($path);
                            }
                        }
                        Notification::make()->title(count($data['files']).' foto(s) enviada(s)')->success()->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()->label('Texto'),
                DeleteAction::make()->label('Remover'),
            ])
            ->toolbarActions([DeleteBulkAction::make()]);
    }
}
