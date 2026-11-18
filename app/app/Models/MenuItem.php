<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    protected $guarded = ['id'];

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MenuItem::class, 'parent_id')->orderBy('position');
    }

    public function href(): string
    {
        return match ($this->link_type) {
            'home' => url('/'),
            'category' => ($c = Category::find($this->link_id)) ? route('category.show', $c->slug) : '#',
            'product' => ($p = Product::find($this->link_id)) ? route('product.show', $p->slug) : '#',
            'page' => ($pg = Page::find($this->link_id)) ? $pg->url() : '#',
            default => $this->url ?: '#',
        };
    }
}
