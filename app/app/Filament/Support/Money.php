<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

/**
 * Campo de valor em reais no admin. O banco guarda centavos (12990); a tela mostra "129,90".
 */
class Money
{
    public static function input(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix('R$')
            ->inputMode('decimal')
            ->mask(RawJs::make('$money($input, \',\', \'.\', 2)'))
            ->formatStateUsing(fn ($state) => $state === null || $state === '' ? null : number_format(((int) $state) / 100, 2, ',', '.'))
            ->dehydrateStateUsing(fn ($state) => self::toCents($state));
    }

    public static function toCents(mixed $state): ?int
    {
        if ($state === null || $state === '') {
            return null;
        }
        if (is_int($state)) {
            return $state;
        }
        $v = str_replace(['R$', ' ', '.'], '', (string) $state);
        $v = str_replace(',', '.', $v);

        return (int) round(((float) $v) * 100);
    }

    public static function format(?int $cents): string
    {
        return money($cents ?? 0);
    }
}
