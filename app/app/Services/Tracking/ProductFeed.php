<?php

namespace App\Services\Tracking;

use App\Models\Product;
use App\Support\StoreSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use XMLWriter;

/**
 * Gera o feed de produtos (RSS 2.0, padrão Google Merchant — aceito por Meta, Google e TikTok) em arquivo,
 * escrevendo em streaming (memória baixa, rápido mesmo na hospedagem compartilhada).
 * Uma linha por variação; g:id usa o MESMO formato dos content_ids do Pixel/API de Conversões.
 */
class ProductFeed
{
    public const PATH = 'feeds/produtos.xml';

    public function build(): int
    {
        $brand = (string) StoreSettings::get('catalog.brand', setting('store.name'));
        $gcat = (string) StoreSettings::get('catalog.google_product_category', 'Health & Beauty > Health Care > Fitness & Nutrition > Vitamins & Supplements');

        $disk = Storage::disk('local');
        $disk->makeDirectory('feeds');
        $tmp = $disk->path(self::PATH.'.tmp');

        $w = new XMLWriter;
        $w->openUri($tmp);
        // um campo por linha: sem isso o arquivo inteiro sai numa linha só e o Meta recusa (limite de 5 MB por linha)
        $w->setIndent(true);
        $w->setIndentString(' ');
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('rss');
        $w->writeAttribute('version', '2.0');
        $w->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
        $w->startElement('channel');
        $w->writeElement('title', (string) setting('store.name'));
        $w->writeElement('link', url('/'));
        $w->writeElement('description', 'Catálogo de produtos');

        $count = 0;
        Product::active()->with(['brand', 'images', 'options', 'categories', 'variants' => fn ($v) => $v->where('is_active', true)])
            ->orderBy('id')->chunk(50, function ($products) use ($w, $brand, $gcat, &$count) {
                foreach ($products as $p) {
                    $desc = Str::limit(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $p->description), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 1500, '…') ?: $p->title;
                    $optIndex = $p->options->pluck('name')->map(fn ($n) => Str::lower($n))->flip();
                    $category = $p->categories->first()?->name;
                    $many = $p->variants->count() > 1;
                    $productUrl = route('product.show', $p->slug);
                    $imageUrls = $p->images->mapWithKeys(fn ($i) => [$i->id => img_url($i->path, 1200)]);

                    foreach ($p->variants as $v) {
                        $v->setRelation('product', $p);
                        $imgId = $v->image_id && isset($imageUrls[$v->image_id]) ? $v->image_id : $p->images->first()?->id;
                        if (! $imgId) {
                            continue; // itens sem imagem são reprovados pelas plataformas
                        }
                        $opts = [$v->option1, $v->option2, $v->option3];
                        $opt = fn (string $name) => isset($optIndex[$name]) ? ($opts[$optIndex[$name]] ?? null) : null;
                        $hasCompare = $v->compare_at_price && $v->compare_at_price > $v->price;

                        $w->startElement('item');
                        foreach ([
                            'g:id' => ContentId::for($v),
                            'g:item_group_id' => (string) $p->id,
                            'g:title' => Str::limit($p->title.($many && $v->title !== 'Padrão' ? ' - '.$v->title : ''), 150, ''),
                            'g:description' => $desc,
                            'g:link' => $productUrl.'?variant='.$v->id,
                            'g:image_link' => $imageUrls[$imgId],
                            'g:availability' => $v->inStock() ? 'in stock' : 'out of stock',
                            'g:price' => number_format(($hasCompare ? $v->compare_at_price : $v->price) / 100, 2, '.', '').' BRL',
                            'g:sale_price' => $hasCompare ? number_format($v->price / 100, 2, '.', '').' BRL' : null,
                            'g:brand' => $p->brand?->name ?: $brand, // marca do fabricante (Growth, Max Titanium); sem marca, a da loja
                            'g:condition' => 'new',
                            'g:google_product_category' => $gcat,
                            'g:product_type' => $category,
                            'g:color' => $opt('cor'),
                            'g:size' => $opt('tamanho'),
                            'g:gender' => 'female',
                            'g:age_group' => 'adult',
                            'g:identifier_exists' => 'no',
                        ] as $tag => $val) {
                            if ($val !== null && $val !== '') {
                                $w->writeElement($tag, (string) $val);
                            }
                        }
                        foreach ($imageUrls->except([$imgId])->take(9) as $url) {
                            $w->writeElement('g:additional_image_link', $url);
                        }
                        $w->startElement('g:shipping');
                        $w->writeElement('g:country', 'BR');
                        $w->writeElement('g:price', '0.00 BRL');
                        $w->endElement();
                        $w->endElement(); // item
                        $count++;
                    }
                    $w->flush();
                }
            });

        $w->endElement(); // channel
        $w->endElement(); // rss
        $w->endDocument();
        $w->flush();

        rename($tmp, $disk->path(self::PATH)); // troca atômica: o feed nunca é servido pela metade

        return $count;
    }
}
