<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Preço efetivo para um subtotal (frete grátis acima de X). */
    public function priceFor(int $subtotal): int
    {
        return $this->free_above !== null && $subtotal >= $this->free_above ? 0 : $this->price;
    }
}
