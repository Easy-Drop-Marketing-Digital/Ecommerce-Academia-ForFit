<?php

namespace App\Services\Tracking;

use App\Models\ProductVariant;
use App\Support\StoreSettings;

/**
 * ID do produto usado nos eventos (content_ids) e no feed de catálogo.
 * Pixel e feed PRECISAM usar o mesmo formato — por isso existe um único ponto que gera o ID.
 * Formato escolhido em Admin → Configurações → Catálogo.
 *
 * Nunca usa IDs de outra plataforma/loja (ex.: Shopify): o catálogo desta loja é independente
 * e IDs alheios ligariam este catálogo ao de outra operação no Meta/Google.
 */
class ContentId
{
    private static ?string $format = null;

    public static function format(): string
    {
        return self::$format ??= (string) StoreSettings::get('catalog.content_id_format', 'variant_id');
    }

    public static function reset(): void
    {
        self::$format = null;
    }

    /**
     * @param  ProductVariant|array  $v  variante (model) ou array com id e sku
     */
    public static function for(ProductVariant|array $v): string
    {
        $id = is_array($v) ? $v['id'] : $v->id;
        $sku = is_array($v) ? ($v['sku'] ?? null) : $v->sku;

        return match (self::format()) {
            'sku' => $sku ?: (string) $id,
            default => (string) $id,
        };
    }
}
