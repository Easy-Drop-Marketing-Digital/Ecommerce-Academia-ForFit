<?php

namespace App\Services\Mail;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\Cart\CartService;
use RuntimeException;

/**
 * Dados de exemplo para o "Enviar e-mail de teste" do admin: usa o pedido/carrinho mais recente
 * para o e-mail ficar idêntico ao real — mas vai só para quem pediu o teste e não altera nada.
 */
class EmailPreview
{
    /** @return array<string, mixed> */
    public function data(string $template, string $to): array
    {
        return match (true) {
            str_starts_with($template, 'cart_recovery') => $this->cart((int) substr($template, -1)),
            $template === 'welcome' => ['customer' => Customer::latest('id')->first() ?? new Customer(['name' => 'Cliente Teste', 'email' => $to])],
            $template === 'password_reset' => ['url' => route('account.password.reset', ['token' => 'link-de-teste', 'email' => $to])],
            $template === 'admin_low_stock' => [
                'variants' => ProductVariant::with('product')->orderBy('stock')->limit(3)->get(),
                'threshold' => (int) setting('stock.low_threshold', 10),
            ],
            $template === 'admin_card_blocked' => ['order' => $this->order($template), 'score' => 60, 'flags' => ['customer_tried_3_other_cards', 'holder_name_mismatch']],
            default => ['order' => $this->order($template)],
        };
    }

    private function order(string $template): Order
    {
        $query = Order::with('items', 'discounts')->latest('id');
        // PIX: prefere um pedido que tenha código PIX, para o QR aparecer no teste
        if (in_array($template, ['order_pix', 'pix_reminder'], true)) {
            $order = (clone $query)->whereNotNull('pix_code')->first();
        }
        $order ??= $query->first();
        if (! $order) {
            throw new RuntimeException('Ainda não há pedidos para usar como exemplo. Faça um pedido de teste na loja primeiro.');
        }

        return $order;
    }

    /** @return array<string, mixed> */
    private function cart(int $step): array
    {
        $cart = Cart::whereHas('items')->latest('id')->first();
        if (! $cart) {
            throw new RuntimeException('Ainda não há carrinhos com produtos para usar como exemplo. Adicione um produto ao carrinho na loja primeiro.');
        }
        $data = app(CartService::class)->toArray($cart->loadMissing('items.variant.product.images', 'items.variant.image'));
        $coupon = $step === 3 ? (trim((string) setting('cart_recovery.coupon')) ?: null) : null;

        return ['cart' => $cart, 'step' => max(1, min(3, $step)), 'items' => $data['items'], 'subtotal' => $data['subtotal'], 'coupon' => $coupon];
    }
}
