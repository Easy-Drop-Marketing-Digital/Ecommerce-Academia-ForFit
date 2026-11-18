<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorSession extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
