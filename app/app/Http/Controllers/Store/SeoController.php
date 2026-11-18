<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class SeoController extends Controller
{
    public function sitemap()
    {
        $xml = Cache::remember('seo.sitemap', 3600, function () {
            $urls = [['loc' => route('home'), 'lastmod' => now()]];
            foreach (Category::active()->get() as $c) {
                $urls[] = ['loc' => route('category.show', $c->slug), 'lastmod' => $c->updated_at];
            }
            foreach (Product::active()->with(['images' => fn ($q) => $q->limit(1)])->get() as $p) {
                $urls[] = ['loc' => route('product.show', $p->slug), 'lastmod' => $p->updated_at, 'image' => $p->images->first() ? img_url($p->images->first()->path, 1200) : null, 'title' => $p->title];
            }
            foreach (Page::where('is_active', true)->get() as $pg) {
                $urls[] = ['loc' => $pg->url(), 'lastmod' => $pg->updated_at];
            }

            $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";
            foreach ($urls as $u) {
                $out .= '<url><loc>'.e($u['loc']).'</loc>'
                    .(isset($u['lastmod']) ? '<lastmod>'.$u['lastmod']->toAtomString().'</lastmod>' : '')
                    .(! empty($u['image']) ? '<image:image><image:loc>'.e($u['image']).'</image:loc><image:title>'.e($u['title']).'</image:title></image:image>' : '')
                    ."</url>\n";
            }

            return $out.'</urlset>';
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots()
    {
        $body = implode("\n", [
            'User-agent: *',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /account',
            'Disallow: /pedido/',
            'Disallow: /search',
            'Disallow: /admin',
            'Disallow: /*?*sort_by=',
            '',
            'Sitemap: '.route('sitemap'),
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }
}
