<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    public const STATUSES = ['pending' => 'Aguardando moderação', 'approved' => 'Publicada', 'hidden' => 'Oculta'];

    protected $guarded = ['id'];

    protected $casts = [
        'media' => 'array',
        'verified' => 'boolean',
        'featured' => 'boolean',
        'replied_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Nota média e total do produto sempre atualizados (vitrine, página de produto e schema do Google)
        static::saved(fn (Review $r) => self::refreshProduct($r->product_id, $r->getOriginal('product_id')));
        static::deleted(fn (Review $r) => self::refreshProduct($r->product_id));
    }

    public static function refreshProduct(?int ...$productIds): void
    {
        foreach (array_unique(array_filter($productIds)) as $id) {
            $stats = static::where('product_id', $id)->where('status', 'approved')
                ->selectRaw('COUNT(*) as n, AVG(rating) as avg')->first();
            Product::whereKey($id)->update(['rating_count' => (int) $stats->n, 'rating_avg' => round((float) $stats->avg, 2)]);
        }
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class)->withTrashed(); }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }

    public function scopeApproved($q) { return $q->where('status', 'approved'); }

    public function images(): array
    {
        return array_values(array_filter($this->media ?? [], fn ($m) => ($m['type'] ?? 'image') === 'image'));
    }

    /** "Maria S." — primeiro nome + inicial do sobrenome (privacidade) */
    public function displayName(): string
    {
        $parts = preg_split('/\s+/', trim($this->author)) ?: ['Cliente'];

        return $parts[0].(count($parts) > 1 ? ' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : '');
    }
}
