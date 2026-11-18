<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountTarget extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    public function target() { return $this->morphTo(); }
}
