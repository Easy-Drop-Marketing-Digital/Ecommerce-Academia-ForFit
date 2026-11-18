<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderEvent extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'data' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
