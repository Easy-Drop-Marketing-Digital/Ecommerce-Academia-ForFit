<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    protected static function booted(): void
    {
        // Mudou o endereço: o antigo continua funcionando
        static::saved(function (self $m) {
            if ($m->wasChanged('slug') && $m->getOriginal('slug')) {
                Redirect::moved('/marcas/'.$m->getOriginal('slug'), '/marcas/'.$m->slug);
            }
        });
    }

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function products(): HasMany { return $this->hasMany(Product::class); }

    public function scopeActive($q) { return $q->where('is_active', true); }

    public function url(): string { return route('brand.show', $this->slug); }
}
