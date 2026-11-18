<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discount extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    public const TYPES = [
        'percentage' => 'Porcentagem',
        'fixed_amount' => 'Valor fixo',
        'free_shipping' => 'Frete grátis',
        'buy_x_get_y' => 'Compre X leve Y',
        'bundle' => 'Combo de produtos',
        'tiered' => 'Progressivo por quantidade',
        'payment_method' => 'Desconto por forma de pagamento',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'rules' => 'array',
        'combinable' => 'boolean',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function targets(): HasMany { return $this->hasMany(DiscountTarget::class); }

    public function isAutomatic(): bool { return $this->code === null; }

    public function isLive(): bool
    {
        return $this->is_active
            && (! $this->starts_at || $this->starts_at->isPast())
            && (! $this->ends_at || $this->ends_at->isFuture())
            && (! $this->usage_limit || $this->used_count < $this->usage_limit);
    }

    public function scopeLive($q)
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->where(fn ($w) => $w->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'));
    }

    /** IDs de produtos elegíveis (null = todos). */
    public function eligibleProductIds(): ?array
    {
        if ($this->applies_to === 'all') {
            return null;
        }
        $ids = [];
        foreach ($this->targets as $t) {
            if ($t->target_type === Product::class) {
                $ids[] = $t->target_id;
            } elseif ($t->target_type === Category::class) {
                $ids = array_merge($ids, \DB::table('category_product')->where('category_id', $t->target_id)->pluck('product_id')->all());
            }
        }

        return array_values(array_unique($ids));
    }
}
