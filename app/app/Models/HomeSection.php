<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeSection extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];
}
