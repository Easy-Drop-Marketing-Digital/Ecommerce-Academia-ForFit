<?php

namespace App\View\Composers;

use App\Models\Menu;
use App\Services\Cart\CartService;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/** Dados compartilhados pelo layout da loja: menus, carrinho e identidade. */
class StoreLayoutComposer
{
    public function __construct(private CartService $carts) {}

    public function compose(View $view): void
    {
        $menus = Cache::remember('layout.menus', 600, function () {
            $map = fn ($m) => $m ? $m->items->map(fn ($i) => [
                'title' => $i->title,
                'url' => $i->href(),
                'children' => $i->children->map(fn ($c) => ['title' => $c->title, 'url' => $c->href()])->all(),
            ])->all() : [];

            $all = Menu::with('items.children')->get()->keyBy('handle');

            return [
                'main' => $map($all->get('main')),
                'footer' => collect(['footer-colecoes', 'footer-informacoes', 'footer-politicas'])
                    ->map(fn ($h) => $all->get($h) ? ['title' => $all[$h]->name, 'items' => $map($all[$h])] : null)
                    ->filter()->values()->all(),
                // mega menu "Categorias" e menu do celular: marcas ativas
                'brands' => \App\Models\Brand::active()->orderBy('position')->get(['name', 'slug'])
                    ->map(fn ($b) => ['title' => $b->name, 'url' => route('brand.show', $b->slug)])->all(),
            ];
        });

        $view->with([
            'menus' => $menus,
            'cartData' => $this->carts->toArray($this->carts->current()),
        ]);
    }
}
