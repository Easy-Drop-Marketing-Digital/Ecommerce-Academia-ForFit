<?php

namespace App\Support;

use App\Models\ShippingMethod;

/**
 * Substitui variáveis nas páginas/políticas pelos dados atuais do admin.
 *
 *   [[store.email]]                     → valor da configuração
 *   [[shipping.table]]                  → tabela das modalidades de frete ativas
 *   <x-if key="store.cnpj">...</x-if>   → trecho só aparece se a configuração estiver preenchida
 *
 * Assim, trocar e-mail, WhatsApp, CNPJ ou prazo no admin atualiza todas as políticas de uma vez.
 */
class ContentTokens
{
    public static function render(?string $html): string
    {
        if (! $html) {
            return '';
        }

        $html = preg_replace_callback('#<x-if key="([a-z0-9_.]+)">(.*?)</x-if>#s', function ($m) {
            $v = StoreSettings::get($m[1]);

            return ($v === null || $v === '' || $v === '0') ? '' : $m[2];
        }, $html);

        return preg_replace_callback('/\[\[([a-z0-9_.]+)\]\]/', fn ($m) => static::value($m[1]), $html);
    }

    private static function value(string $key): string
    {
        return match ($key) {
            'shipping.table' => static::shippingTable(),
            'pix.validity' => static::pixValidity(),
            'year' => (string) now()->year,
            default => e((string) StoreSettings::get($key, '')),
        };
    }

    private static function pixValidity(): string
    {
        $d = (int) StoreSettings::get('nixfy.pix_expires_in_days', 1);

        return $d <= 1 ? '24 horas' : "$d dias";
    }

    private static function shippingTable(): string
    {
        try {
            $methods = ShippingMethod::where('is_active', true)->orderBy('position')->get();
        } catch (\Throwable) {
            return '';
        }
        if ($methods->isEmpty()) {
            return '';
        }

        $rows = $methods->map(function ($m) {
            $price = $m->price === 0 ? 'Grátis' : 'R$ '.number_format($m->price / 100, 2, ',', '.');
            if ($m->free_above !== null && $m->price > 0) {
                $price .= ' (grátis acima de R$ '.number_format($m->free_above / 100, 2, ',', '.').')';
            }
            $days = $m->min_days && $m->max_days ? "{$m->min_days} a {$m->max_days} dias úteis" : ($m->description ?: '—');

            return '<tr><td>'.e($m->name).'</td><td>'.e($price).'</td><td>'.e($days).'</td></tr>';
        })->implode('');

        return '<table class="policy-table"><thead><tr><th>Modalidade</th><th>Valor</th><th>Prazo estimado</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }
}
