<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProductImage extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // foto removida no admin: apaga também os 5 tamanhos do disco
        static::deleted(fn (ProductImage $i) => \App\Support\ImageOptimizer::deleteSet($i->path));
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    public function url(): string
    {
        return Str::startsWith($this->path, ['http://', 'https://']) ? $this->path : asset('storage/'.$this->path);
    }
}
