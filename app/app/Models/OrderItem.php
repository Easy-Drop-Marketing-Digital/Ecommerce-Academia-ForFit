<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['properties' => 'array'];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Order::class); }

    public function variant(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Product::class); }
}
