<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Carrinho persistido no banco, identificado por cookie de 1ª parte (60 dias).
 * Persistir no banco (e não só na sessão) é o que permite recuperar carrinho abandonado por e-mail
 * e manter o carrinho entre dispositivos quando a cliente faz login.
 */
class CartService
{
    public const COOKIE = 'sd_cart';

    private ?Cart $cart = null;

    public function __construct(private Request $request) {}

    /** Carrinho atual (sem criar). */
    public function current(): ?Cart
    {
        if ($this->cart) {
            return $this->cart;
        }
        $token = $this->request->cookie(self::COOKIE);
        if (! $token || ! Str::isUuid($token)) {
            return null;
        }

        return $this->cart = Cart::where('token', $token)->where('status', '!=', 'converted')
            ->with('items.variant.product.images', 'items.variant.image')->first();
    }

    public function currentOrCreate(): Cart
    {
        if ($cart = $this->current()) {
            return $cart;
        }
        $this->cart = Cart::create([
            'token' => (string) Str::uuid(),
            'customer_id' => auth('customer')->id(),
            'attribution' => $this->request->session()->get('attribution'),
        ]);
        Cookie::queue(self::COOKIE, $this->cart->token, 60 * 24 * 60, null, null, null, true, false, 'lax');

        return $this->cart->setRelation('items', collect());
    }

    /** @throws CartException */
    public function add(int $variantId, int $qty = 1): Cart
    {
        $variant = ProductVariant::with('product')->where('is_active', true)->findOrFail($variantId);
        if ($variant->product->status !== 'active') {
            throw new CartException('Este produto não está mais disponível.');
        }

        $cart = $this->currentOrCreate();
        $item = $cart->items()->firstOrNew(['variant_id' => $variant->id]);
        if (($item->quantity ?? 0) >= 99) {
            throw new CartException('Limite de 99 unidades por item. Para pedidos maiores, fale com o atendimento.');
        }
        $newQty = min(99, ($item->quantity ?? 0) + max(1, $qty));
        if (! $variant->inStock($newQty)) {
            throw new CartException('Quantidade indisponível em estoque para esta opção.');
        }
        $item->quantity = $newQty;
        $item->save();
        $cart->touch();

        return $this->fresh($cart);
    }

    /** @throws CartException */
    public function update(int $itemId, int $qty): Cart
    {
        $cart = $this->current() ?? throw new CartException('Carrinho não encontrado.');
        $item = $cart->items()->with('variant.product')->findOrFail($itemId);
        if ($qty <= 0) {
            $item->delete();
        } else {
            $qty = min(99, $qty);
            if (! $item->variant->inStock($qty)) {
                throw new CartException('Quantidade indisponível em estoque para esta opção.');
            }
            $item->update(['quantity' => $qty]);
        }
        $cart->touch();

        return $this->fresh($cart);
    }

    public function remove(int $itemId): Cart
    {
        return $this->update($itemId, 0);
    }

    /** Estrutura usada pela gaveta do carrinho (JSON) e pela página /cart. */
    public function toArray(?Cart $cart): array
    {
        if (! $cart) {
            return ['token' => null, 'count' => 0, 'subtotal' => 0, 'compare_total' => 0, 'items' => []];
        }

        $items = $cart->items->map(function ($i) {
            $v = $i->variant;
            $p = $v->product;
            $img = $v->image ?? $p->images->first();

            return [
                'id' => $i->id,
                'variant_id' => $v->id,
                'product_id' => $p->id,
                'title' => $p->title,
                'variant_title' => $v->title === 'Padrão' ? null : $v->title,
                'url' => route('product.show', $p->slug).'?variant='.$v->id,
                'image' => $img ? img_url($img->path, 200) : img_url(null),
                'quantity' => $i->quantity,
                'price' => $v->price,
                'compare_at_price' => $v->compare_at_price,
                'line_total' => $v->price * $i->quantity,
                'sku' => $v->sku,
                'content_id' => \App\Services\Tracking\ContentId::for($v),
            ];
        })->values();

        $subtotal = (int) $items->sum('line_total');
        $compare = (int) $items->sum(fn ($i) => ($i['compare_at_price'] ?: $i['price']) * $i['quantity']);

        return [
            'token' => $cart->token,
            'count' => (int) $items->sum('quantity'),
            'subtotal' => $subtotal,
            'subtotal_formatted' => money($subtotal),
            'compare_total' => $compare,
            'savings' => max(0, $compare - $subtotal),
            'savings_formatted' => money(max(0, $compare - $subtotal)),
            'items' => $items->all(),
        ];
    }

    private function fresh(Cart $cart): Cart
    {
        return $this->cart = $cart->fresh(['items.variant.product.images', 'items.variant.image']);
    }
}
