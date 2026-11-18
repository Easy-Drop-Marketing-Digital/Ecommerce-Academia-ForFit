<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Menu;
use App\Models\Page;
use App\Models\ShippingMethod;
use Illuminate\Database\Seeder;

/**
 * Conteúdo inicial: políticas e páginas institucionais, menus do rodapé e modalidades de frete.
 * Rodar: php artisan db:seed --class=StoreContentSeeder   (idempotente — atualiza pelo slug/handle)
 *
 * Textos das políticas ficam em database/seeders/pages/*.html, com [[variáveis]] preenchidas
 * pelos dados de Admin → Configurações (CNPJ, e-mail, WhatsApp, prazos...).
 */
class StoreContentSeeder extends Seeder
{
    private const PAGES = [
        // slug                        título
        'sobre-nos' => 'Sobre Nós',
        'contact-information' => 'Contato',
        'rastrear-pedido' => 'Rastrear Pedido',
        'site-confiavel' => 'Site Confiável',
        'legal-notice' => 'Aviso Legal',
        'terms-of-service' => 'Termos de Serviço',
        'shipping-policy' => 'Política de Frete',
        'politicas-de-pagamento' => 'Política de Pagamento',
        'privacy-policy' => 'Política de Privacidade',
        'refund-policy' => 'Política de Devolução e Reembolso',
    ];

    public function run(): void
    {
        $pages = [];
        foreach (self::PAGES as $slug => $title) {
            $pages[$slug] = Page::updateOrCreate(['slug' => $slug], [
                'title' => $title,
                'content' => file_get_contents(__DIR__."/pages/$slug.html"),
                'is_active' => true,
                'seo_title' => "$title | ".setting('store.name'),
            ]);
        }

        $this->menus($pages);
        $this->shipping();
    }

    private function menus(array $pages): void
    {
        $cat = fn (string $slug) => Category::where('slug', $slug)->value('id');

        $menus = [
            'footer-colecoes' => ['Coleções', [
                ['Conjuntos', 'category', $cat('conjuntos')],
                ['Vestidos', 'category', $cat('vestidos')],
                ['Calças', 'category', $cat('calcas')],
                ['Blusas', 'category', $cat('blusas')],
                ['Calçados', 'category', $cat('calcados')],
                ['Black Friday', 'category', $cat('black-friday')],
            ]],
            'footer-informacoes' => ['Informações', [
                ['Sobre Nós', 'page', $pages['sobre-nos']->id],
                ['Contato', 'page', $pages['contact-information']->id],
                ['Rastrear Pedido', 'page', $pages['rastrear-pedido']->id],
                ['Site Confiável', 'page', $pages['site-confiavel']->id],
                ['Aviso Legal', 'page', $pages['legal-notice']->id],
                ['Termos de Serviço', 'page', $pages['terms-of-service']->id],
            ]],
            'footer-politicas' => ['Políticas', [
                ['Política de Frete', 'page', $pages['shipping-policy']->id],
                ['Política de Pagamento', 'page', $pages['politicas-de-pagamento']->id],
                ['Política de Privacidade', 'page', $pages['privacy-policy']->id],
                ['Política de Devolução e Reembolso', 'page', $pages['refund-policy']->id],
            ]],
        ];

        foreach ($menus as $handle => [$name, $items]) {
            $menu = Menu::updateOrCreate(['handle' => $handle], ['name' => $name]);
            $menu->items()->delete();
            foreach ($items as $pos => [$title, $type, $id]) {
                if ($id) {
                    $menu->items()->create(['title' => $title, 'link_type' => $type, 'link_id' => $id, 'position' => $pos]);
                }
            }
        }
    }

    private function shipping(): void
    {
        ShippingMethod::updateOrCreate(['name' => 'Frete Grátis'], [
            'description' => 'Entrega para todo o Brasil com código de rastreio',
            'price' => 0,
            'min_days' => 10,
            'max_days' => 25,
            'position' => 0,
            'is_active' => true,
        ]);

        // Preço/prazo a definir pelo cliente em Admin → Frete. Fica inativo até lá para não exibir valor errado.
        ShippingMethod::firstOrCreate(['name' => 'Entrega Expressa'], [
            'description' => 'Envio prioritário',
            'price' => 2990,
            'min_days' => null,
            'max_days' => null,
            'position' => 1,
            'is_active' => false,
        ]);
    }
}
