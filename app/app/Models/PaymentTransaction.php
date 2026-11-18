<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
    ];
}
