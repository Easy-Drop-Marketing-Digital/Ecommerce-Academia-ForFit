<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Page;

class PageController extends Controller
{
    public function page(string $slug)
    {
        $page = Page::where('slug', $slug)->where('is_active', true)->firstOrFail();
        // Políticas têm URL própria (/policies/...) — mantém um endereço canônico só
        if ($page->isPolicy()) {
            return redirect($page->url(), 301);
        }

        return $this->render($page);
    }

    public function policy(string $slug)
    {
        $page = Page::where('slug', $slug)->where('is_active', true)->firstOrFail();
        if (! $page->isPolicy()) {
            return redirect($page->url(), 301);
        }

        return $this->render($page);
    }

    private function render(Page $page)
    {
        return view('store.page', ['page' => $page]);
    }
}
