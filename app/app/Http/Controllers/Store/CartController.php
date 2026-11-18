<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Services\Cart\CartException;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class CartController extends Controller
{
    public function __construct(private CartService $carts) {}

    public function show()
    {
        return view('store.cart', ['cart' => $this->carts->toArray($this->carts->current())]);
    }

    public function json()
    {
        return response()->json($this->carts->toArray($this->carts->current()));
    }

    public function add(Request $request)
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        return $this->respond($request, fn () => $this->carts->add((int) $data['variant_id'], (int) ($data['quantity'] ?? 1)), 'added');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        return $this->respond($request, fn () => $this->carts->update((int) $data['item_id'], (int) $data['quantity']));
    }

    /** Link do e-mail de carrinho abandonado: restaura o carrinho neste navegador. */
    public function recover(string $token)
    {
        $cart = Cart::where('token', $token)->where('status', '!=', 'converted')->first();
        if (! $cart) {
            return redirect()->route('home');
        }
        Cookie::queue(CartService::COOKIE, $cart->token, 60 * 24 * 60, null, null, null, true, false, 'lax');

        return redirect()->route('cart.show', ['recover' => 1]);
    }

    /**
     * Link de carrinho no formato da Shopify: /cart/123:2,456:1?discount=CUPOM
     * (ids da Shopify ou da loja nova) → coloca os itens no carrinho e vai para o checkout.
     */
    public function permalink(Request $request, string $items)
    {
        foreach (explode(',', $items) as $pair) {
            [$id, $qty] = array_map('intval', explode(':', $pair) + [1 => 1]);
            $variant = \App\Models\ProductVariant::where('is_active', true)
                ->where(fn ($q) => $q->whereKey($id)->orWhere('shopify_id', $id))->first();
            if (! $variant) {
                continue;
            }
            try {
                $this->carts->add($variant->id, max(1, min(99, $qty)));
            } catch (CartException) {
                // item esgotado/indisponível: segue com os demais
            }
        }
        if ($code = $request->query('discount')) {
            $this->rememberDiscount((string) $code);
        }
        $cart = $this->carts->current();

        return redirect()->route($cart && $cart->items()->exists() ? 'checkout' : 'cart.show');
    }

    /** Link de cupom (formato da Shopify): /discount/CUPOM?redirect=/collections/vestidos */
    public function discountLink(Request $request, string $code)
    {
        $this->rememberDiscount($code);
        $to = (string) $request->query('redirect', '/');
        // só caminhos da própria loja (nada de redirecionar para outro site)
        if (! str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')) {
            $to = '/';
        }

        return redirect($to)->with('toast', 'Cupom '.mb_strtoupper($code).' ativado! O desconto aparece no checkout.');
    }

    private function rememberDiscount(string $code): void
    {
        $code = mb_strtoupper(trim(mb_substr($code, 0, 60)));
        if ($code === '') {
            return;
        }
        session(['discount_code' => $code]);
        if ($cart = $this->carts->current()) {
            $cart->forceFill(['discount_code' => $code])->save();
        }
    }

    private function respond(Request $request, callable $action, ?string $event = null)
    {
        try {
            $cart = $action();
        } catch (CartException $e) {
            return $request->expectsJson()
                ? response()->json(['error' => $e->getMessage()], 422)
                : back()->with('cart_error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json($this->carts->toArray($cart) + ['event' => $event]);
        }

        return redirect()->route('cart.show');
    }
}
