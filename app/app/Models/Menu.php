<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    protected $guarded = ['id'];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MenuItem::class)->whereNull('parent_id')->orderBy('position');
    }

    public function allItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MenuItem::class);
    }
}
