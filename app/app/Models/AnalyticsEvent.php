<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsEvent extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function visitorSession(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(VisitorSession::class);
    }
}
