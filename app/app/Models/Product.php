<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    protected static function booted(): void
    {
        // Mudou o endereço: o antigo continua funcionando (anúncios, Google, links compartilhados)
        static::saved(function (self $m) {
            if ($m->wasChanged('slug') && $m->getOriginal('slug')) {
                Redirect::moved('/products/'.$m->getOriginal('slug'), '/products/'.$m->slug);
            }
        });
    }

    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'tags' => 'array',
        'specs' => 'array',
        'track_stock' => 'boolean',
        'allow_backorder' => 'boolean',
        'published_at' => 'datetime',
        'rating_avg' => 'float',
    ];

    public function categories(): BelongsToMany { return $this->belongsToMany(Category::class)->withPivot('position'); }

    public function brand(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Brand::class); }

    public function images(): HasMany { return $this->hasMany(ProductImage::class)->orderBy('position'); }

    public function options(): HasMany { return $this->hasMany(ProductOption::class)->orderBy('position'); }

    public function variants(): HasMany { return $this->hasMany(ProductVariant::class)->orderBy('position'); }

    public function reviews(): HasMany { return $this->hasMany(Review::class); }

    public function scopeActive($q) { return $q->where('status', 'active'); }

    /** Recalcula os campos de cache usados em listagens/ordenação. */
    public function refreshPriceCache(): void
    {
        $variants = $this->variants()->where('is_active', true)->get();
        $this->min_price = (int) $variants->min('price');
        $this->max_compare_price = $variants->max('compare_at_price');
        if ($this->isDirty()) {
            $this->saveQuietly();
            \App\Support\PageCache::flush();
        }
    }

    public function totalStock(): int { return (int) $this->variants()->sum('stock'); }
}
