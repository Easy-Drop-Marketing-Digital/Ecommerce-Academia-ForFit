<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Discount;
use App\Models\HomeSection;
use App\Models\Menu;
use App\Models\Setting;
use App\Models\ShippingMethod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Vitrine da ForFit: identidade (logos, favicon, imagem de compartilhamento), banners, seções da home,
 * menu principal (barra de categorias), frete (grátis acima de R$ 199, expresso e retirada na academia)
 * e cupom de boas-vindas.
 *
 *   php artisan db:seed --class=ForFitStorefrontSeeder
 *
 * Rode depois do catálogo (php artisan forfit:catalogo). Idempotente; sobrescreve home, menu principal e banners da home.
 * Arquivos da marca: ../forfit-marca/arquivos (ou database/marca no pacote de publicação).
 */
class ForFitStorefrontSeeder extends Seeder
{
    public function run(): void
    {
        $this->identity();
        $this->banners();
        $this->home();
        $this->menus();
        $this->shipping();
        $this->coupon();

        foreach (['home.sections', 'layout.menus', 'settings.all'] as $k) {
            Cache::forget($k);
        }
        \App\Support\PageCache::flush();
    }

    private function source(): string
    {
        foreach ([base_path('../forfit-marca/arquivos'), database_path('marca')] as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }
        throw new \RuntimeException('Pasta da marca não encontrada (forfit-marca/arquivos ou database/marca).');
    }

    private function put(string $file, string $to): string
    {
        Storage::disk('public')->put($to, file_get_contents($this->source().'/'.$file));

        return $to;
    }

    private function identity(): void
    {
        Setting::set('store.logo', $this->put('logo-forfit.png', 'marca/logo-forfit.png'));
        Setting::set('store.logo_light', $this->put('logo-forfit-branca.png', 'marca/logo-forfit-branca.png'));
        Setting::set('store.share_image', $this->put('compartilhamento-1200x630.png', 'marca/compartilhamento-1200x630.png'));
        foreach (['favicon.ico', 'apple-touch-icon.png'] as $f) {
            copy($this->source()."/$f", public_path($f));
        }
        foreach (['simbolo-192.png' => 'icon-192.png', 'simbolo-512.png' => 'icon-512.png'] as $from => $to) {
            copy($this->source()."/$from", public_path($to));
        }
    }

    private function banners(): void
    {
        $cat = fn (string $slug) => ($c = Category::where('slug', $slug)->first()) ? route('category.show', $c->slug, false) : '/';
        $slides = [
            ['revenda-autorizada', 'Growth e Max Titanium num só lugar', $cat('mais-vendidos')],
            ['pix-10-off', '10% OFF no PIX', $cat('whey-protein')],
            ['creatina', 'Creatina todo dia', $cat('creatina')],
            ['frete-gratis', 'Frete grátis acima de R$ 199', $cat('mais-vendidos')],
        ];
        Banner::where('placement', 'home_hero')->delete();
        foreach ($slides as $pos => [$file, $title, $link]) {
            Banner::create([
                'placement' => 'home_hero', 'title' => $title, 'alt' => $title, 'link' => $link, 'position' => $pos, 'is_active' => true,
                'image_desktop' => $this->put("banners/$file-desktop.png", "banners/forfit-$file-desktop.png"),
                'image_mobile' => $this->put("banners/$file-mobile.png", "banners/forfit-$file-mobile.png"),
            ]);
        }
        foreach (Brand::all() as $brand) {
            if (is_file($this->source()."/banners/marca-{$brand->slug}.png")) {
                $brand->update(['banner_image' => $this->put("banners/marca-{$brand->slug}.png", "brands/banner-{$brand->slug}.png")]);
            }
        }
    }

    private function home(): void
    {
        $id = fn (string $slug) => Category::where('slug', $slug)->value('id');
        $sections = [
            ['slideshow', null, ['placement' => 'home_hero', 'autoplay' => 6000]],
            ['benefits', null, []],
            ['brand_list', 'Compre por marca', []],
            ['product_list', 'Mais vendidos', ['category_id' => $id('mais-vendidos'), 'limit' => 16, 'view_all_label' => 'Ver todos']],
            ['collection_list', 'Compre por categoria', ['category_ids' => array_values(array_filter(array_map($id, ['whey-protein', 'creatina', 'pre-treino', 'aminoacidos', 'termogenicos', 'vitaminas-e-saude', 'hipercalorico-e-carboidratos', 'barras-e-snacks', 'kits'])))]],
            ['product_list', 'Whey Protein', ['category_id' => $id('whey-protein'), 'limit' => 12, 'view_all_label' => 'Ver todos os wheys']],
            ['product_list', 'Creatina', ['category_id' => $id('creatina'), 'limit' => 12, 'view_all_label' => 'Ver todas as creatinas']],
            ['product_list', 'Pré-treino', ['category_id' => $id('pre-treino'), 'limit' => 12, 'view_all_label' => 'Ver todos os pré-treinos']],
            ['product_list', 'Kits e Combos', ['category_id' => $id('kits'), 'limit' => 12, 'view_all_label' => 'Ver todos os kits']],
            ['product_list', 'Vitaminas e Saúde', ['category_id' => $id('vitaminas-e-saude'), 'limit' => 12, 'view_all_label' => 'Ver todos']],
        ];
        HomeSection::query()->delete();
        foreach ($sections as $pos => [$type, $title, $settings]) {
            HomeSection::create(['type' => $type, 'title' => $title, 'settings' => $settings, 'position' => $pos, 'is_active' => true]);
        }
    }

    private function menus(): void
    {
        $main = Menu::updateOrCreate(['handle' => 'main'], ['name' => 'Menu principal (barra de categorias)']);
        $main->items()->delete();
        $items = [
            ['Mais vendidos', 'mais-vendidos'], ['Whey Protein', 'whey-protein'], ['Creatina', 'creatina'], ['Pré-treino', 'pre-treino'],
            ['Aminoácidos', 'aminoacidos'], ['Termogênicos', 'termogenicos'], ['Vitaminas', 'vitaminas-e-saude'],
            ['Hipercalóricos', 'hipercalorico-e-carboidratos'], ['Barras e Snacks', 'barras-e-snacks'], ['Kits', 'kits'],
        ];
        foreach ($items as $pos => [$title, $slug]) {
            if ($cid = Category::where('slug', $slug)->value('id')) {
                $main->items()->create(['title' => $title, 'link_type' => 'category', 'link_id' => $cid, 'position' => $pos]);
            }
        }

        $footer = Menu::updateOrCreate(['handle' => 'footer-colecoes'], ['name' => 'Categorias']);
        $footer->items()->delete();
        foreach (array_slice($items, 1) as $pos => [$title, $slug]) {
            if ($cid = Category::where('slug', $slug)->value('id')) {
                $footer->items()->create(['title' => $title, 'link_type' => 'category', 'link_id' => $cid, 'position' => $pos]);
            }
        }
        foreach (Brand::orderBy('position')->get() as $i => $b) {
            $footer->items()->create(['title' => $b->name, 'link_type' => 'url', 'url' => '/marcas/'.$b->slug, 'position' => 50 + $i]);
        }
    }

    private function shipping(): void
    {
        ShippingMethod::query()->update(['is_active' => false]);
        ShippingMethod::updateOrCreate(['name' => 'Entrega padrão'], [
            'description' => 'Grátis acima de R$ 199 — com código de rastreio', 'price' => 1990, 'free_above' => 19900,
            'min_days' => 3, 'max_days' => 10, 'position' => 0, 'is_active' => true,
        ]);
        ShippingMethod::updateOrCreate(['name' => 'Entrega Expressa'], [
            'description' => 'Envio prioritário', 'price' => 2990, 'free_above' => null, 'min_days' => 1, 'max_days' => 4, 'position' => 1, 'is_active' => true,
        ]);
        ShippingMethod::updateOrCreate(['name' => 'Retirar na academia ForFit'], [
            'description' => 'Grátis — retire na unidade após o aviso de pedido separado', 'price' => 0, 'free_above' => null,
            'min_days' => 0, 'max_days' => 1, 'position' => 2, 'is_active' => true,
        ]);
    }

    private function coupon(): void
    {
        Discount::updateOrCreate(['code' => 'FORFIT10'], [
            'title' => 'Boas-vindas: 10% na primeira compra', 'type' => 'percentage', 'value' => 10, 'is_active' => true,
            'min_subtotal' => 9900, 'usage_limit_per_customer' => 1,
        ]);
    }
}
