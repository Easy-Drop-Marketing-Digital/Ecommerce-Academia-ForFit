<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'checkout_data' => 'array',
        'attribution' => 'array',
        'checkout_started_at' => 'datetime',
        'recovered_at' => 'datetime',
        'last_recovery_email_at' => 'datetime',
    ];

    public function items(): HasMany { return $this->hasMany(CartItem::class); }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }

    public function shippingMethod(): BelongsTo { return $this->belongsTo(ShippingMethod::class); }

    public function itemCount(): int { return (int) $this->items->sum('quantity'); }

    public function recoveryUrl(): string { return route('cart.recover', $this->token); }
}
