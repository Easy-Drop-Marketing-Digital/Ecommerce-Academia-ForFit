<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    protected static function booted(): void
    {
        // Mudou o endereço: o antigo continua funcionando (anúncios, Google, links compartilhados)
        static::saved(function (self $m) {
            if ($m->wasChanged('slug') && $m->getOriginal('slug')) {
                Redirect::moved('/collections/'.$m->getOriginal('slug'), '/collections/'.$m->slug);
            }
        });
    }

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function parent(): BelongsTo { return $this->belongsTo(Category::class, 'parent_id'); }

    public function children(): HasMany { return $this->hasMany(Category::class, 'parent_id')->orderBy('position'); }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('position')->orderByPivot('position');
    }

    public function scopeActive($q) { return $q->where('is_active', true); }
}
