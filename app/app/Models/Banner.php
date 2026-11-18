<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /** Banners ativos e dentro da janela de agendamento. */
    public function scopeLive($q, string $placement)
    {
        return $q->where('placement', $placement)->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('position');
    }

    public function imageUrl(string $field = 'image_desktop'): ?string
    {
        $v = $this->{$field} ?: $this->image_desktop;

        return \Illuminate\Support\Str::startsWith($v, ['http://', 'https://']) ? $v : asset('storage/'.$v);
    }
}
