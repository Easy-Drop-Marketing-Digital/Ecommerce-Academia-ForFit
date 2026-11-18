<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Cliente da loja (área "Minha conta" usa o guard `customer`). */
class Customer extends Authenticatable
{
    use \Illuminate\Notifications\Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'accepts_marketing' => 'boolean',
        'tags' => 'array',
        'last_order_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function orders(): HasMany { return $this->hasMany(Order::class)->latest(); }

    public function addresses(): HasMany { return $this->hasMany(CustomerAddress::class); }

    public function firstName(): string { return explode(' ', trim($this->name))[0]; }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\CustomerResetPassword($token));
    }

    /** Recalcula métricas a partir dos pedidos pagos. */
    public function refreshStats(): void
    {
        $paid = $this->orders()->whereNotNull('paid_at')->whereNotIn('status', ['canceled', 'refunded']);
        $this->orders_count = $paid->count();
        $this->total_spent = (int) $paid->sum('total');
        $this->last_order_at = $paid->max('paid_at');
        $this->saveQuietly();
    }
}
