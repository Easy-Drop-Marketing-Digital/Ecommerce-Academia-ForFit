<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Order extends Model
{
    public const STATUSES = [
        'pending' => 'Aguardando pagamento',
        'paid' => 'Pago',
        'processing' => 'Em separação',
        'shipped' => 'Enviado',
        'delivered' => 'Entregue',
        'canceled' => 'Cancelado',
        'refunded' => 'Reembolsado',
    ];

    public const PAYMENT_STATUSES = [
        'pending' => 'Pendente',
        'processing' => 'Em processamento',
        'paid' => 'Pago',
        'refused' => 'Recusado',
        'canceled' => 'Cancelado',
        'refunded' => 'Estornado',
        'chargeback' => 'Chargeback',
        'med' => 'Em mediação (MED PIX)',
        'failed' => 'Falha',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'shipping_address' => 'array',
        'attribution' => 'array',
        'pix_expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'canceled_at' => 'datetime',
        'purchase_event_sent_at' => 'datetime',
        'risk_flags' => 'array',
        'stock_released_at' => 'datetime',
        'last_gateway_check_at' => 'datetime',
    ];

    /** Devolve ao estoque o que foi reservado no checkout (uma única vez). */
    public function releaseStock(string $reason = 'cancel'): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($reason) {
            $fresh = static::whereKey($this->id)->lockForUpdate()->first();
            if ($fresh->stock_released_at) {
                return;
            }
            foreach ($this->items()->with('variant.product')->get() as $item) {
                if ($item->variant && $item->variant->product?->track_stock) {
                    $item->variant->adjustStock($item->quantity, $reason, $this);
                }
            }
            $fresh->forceFill(['stock_released_at' => now()])->saveQuietly();
            $this->stock_released_at = $fresh->stock_released_at;
        });
        $this->log('stock', 'Estoque devolvido ('.$reason.')');
    }

    protected static function booted(): void
    {
        static::creating(function (Order $o) {
            $o->public_token ??= (string) Str::uuid();
            $o->event_id ??= 'ord_'.Str::lower(Str::random(24));
            $o->number ??= (int) (DB::table('orders')->lockForUpdate()->max('number') ?: 10000) + 1;
        });
    }

    public function items(): HasMany { return $this->hasMany(OrderItem::class); }

    public function discounts(): HasMany { return $this->hasMany(OrderDiscount::class); }

    public function events(): HasMany { return $this->hasMany(OrderEvent::class)->latest('id'); }

    public function transactions(): HasMany { return $this->hasMany(PaymentTransaction::class); }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }

    public function cart(): BelongsTo { return $this->belongsTo(Cart::class); }

    public function isPaid(): bool { return $this->paid_at !== null; }

    public function statusLabel(): string { return self::STATUSES[$this->status] ?? $this->status; }

    public function log(string $type, string $message, array $data = [], ?int $userId = null): void
    {
        $this->events()->create(['type' => $type, 'message' => $message, 'data' => $data ?: null, 'user_id' => $userId]);
    }

    public function publicUrl(): string { return route('order.status', $this->public_token); }
}
