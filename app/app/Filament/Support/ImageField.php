<?php

namespace App\Filament\Support;

use App\Support\ImageOptimizer;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;

/**
 * Upload de imagem que já converte para WebP no tamanho certo (página leve no celular).
 * Imagens antigas guardadas como URL externa continuam funcionando até serem substituídas.
 */
class ImageField
{
    public static function make(string $name, string $label, string $directory, int $maxWidth): FileUpload
    {
        return FileUpload::make($name)
            ->label($label)
            ->image()
            ->disk('public')
            ->directory($directory)
            ->maxSize(10240)
            ->imagePreviewHeight('160')
            ->formatStateUsing(fn ($state) => is_string($state) && str_starts_with($state, 'http') ? null : $state)
            ->dehydrateStateUsing(function ($state, $record) use ($name, $maxWidth) {
                $path = is_array($state) ? (array_values($state)[0] ?? null) : $state;
                if (! $path) {
                    // campo vazio: mantém a URL externa antiga, se havia
                    $old = $record?->getOriginal($name);

                    return is_string($old) && str_starts_with($old, 'http') ? $old : null;
                }
                if (str_ends_with($path, '.webp') && str_contains($path, '-w')) {
                    return $path; // já otimizada
                }
                $webp = ImageOptimizer::toWebp($path, $maxWidth);
                if ($webp !== $path) {
                    Storage::disk('public')->delete($path);
                }

                return $webp;
            });
    }
}
