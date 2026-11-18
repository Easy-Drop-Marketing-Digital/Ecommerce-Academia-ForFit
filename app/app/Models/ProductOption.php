<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductOption extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['values' => 'array'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
