<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ProductVariant extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        // Preço "a partir de" e preço riscado da vitrine acompanham qualquer mudança nas variações
        $refresh = fn (ProductVariant $v) => $v->product()->first()?->refreshPriceCache();
        static::saved(function (ProductVariant $v) use ($refresh) {
            if ($v->wasChanged(['price', 'compare_at_price', 'is_active']) || $v->wasRecentlyCreated) {
                $refresh($v);
            }
        });
        static::deleted($refresh);

        // Cache de página: só limpa se mudou algo visível — preço/ativo, ou a variação esgotou/voltou.
        // Venda comum (estoque 50 → 49) não limpa, senão o cache não serviria em pico de vendas.
        static::saved(function (ProductVariant $v) {
            $crossed = $v->wasChanged('stock') && ((int) $v->getOriginal('stock') > 0) !== ((int) $v->stock > 0);
            if ($v->wasRecentlyCreated || $crossed || $v->wasChanged(['price', 'compare_at_price', 'is_active', 'title', 'image_id'])) {
                \App\Support\PageCache::flush();
            }
        });
        static::deleted(fn () => \App\Support\PageCache::flush());

        // Sem SKU? Ganha um código neutro da loja (AS-00123). O código de origem fica em supplier_sku.
        static::created(function (ProductVariant $v) {
            if (! $v->sku) {
                $v->forceFill(['sku' => self::publicSku($v->id)])->saveQuietly();
            }
        });
    }

    /** SKU público da loja: iniciais do nome da loja + id (ex.: ForFit → FF-00123) */
    public static function publicSku(int $id): string
    {
        $name = \Illuminate\Support\Str::ascii(trim((string) setting('store.name', 'Loja')));
        $prefix = collect(preg_split('/\s+/', $name))->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
        // nome de uma palavra só com maiúsculas internas (ForFit → FF)
        if (mb_strlen($prefix) < 2 && preg_match_all('/[A-Z]/', $name, $caps) >= 2) {
            $prefix = implode('', array_slice($caps[0], 0, 3));
        }
        $prefix = $prefix ?: 'LJ';

        return $prefix.'-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    public function image(): BelongsTo { return $this->belongsTo(ProductImage::class, 'image_id'); }

    public function inStock(int $qty = 1): bool
    {
        return ! $this->product->track_stock || $this->product->allow_backorder || $this->stock >= $qty;
    }

    /**
     * Movimenta estoque de forma atômica (evita overselling em pico de Black Friday).
     * Retorna false se não houver saldo suficiente para a baixa.
     */
    public function adjustStock(int $delta, string $reason, ?Model $reference = null, ?int $userId = null): bool
    {
        return DB::transaction(function () use ($delta, $reason, $reference, $userId) {
            $row = static::whereKey($this->id)->lockForUpdate()->first();
            $new = $row->stock + $delta;
            if ($delta < 0 && $new < 0 && ! $this->product->allow_backorder) {
                return false;
            }
            $row->update(['stock' => $new]);
            StockMovement::create([
                'variant_id' => $this->id,
                'quantity' => $delta,
                'stock_after' => $new,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'user_id' => $userId,
            ]);
            $this->stock = $new;

            return true;
        });
    }
}
