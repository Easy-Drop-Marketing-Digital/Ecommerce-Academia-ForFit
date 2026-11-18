<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Support\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReviewController extends Controller
{
    /** Lista paginada para a página de produto ("Carregar mais" e filtro por nota). */
    public function index(Request $request, string $slug)
    {
        $product = Product::active()->where('slug', $slug)->firstOrFail();
        $rating = (int) $request->query('nota');

        $page = Review::approved()->where('product_id', $product->id)
            ->when($rating >= 1 && $rating <= 5, fn ($q) => $q->where('rating', $rating))
            ->when($request->boolean('fotos'), fn ($q) => $q->whereNotNull('media'))
            ->orderByDesc('featured')->orderByDesc('created_at')
            ->paginate(8);

        return response()->json([
            'data' => collect($page->items())->map(fn (Review $r) => self::present($r))->all(),
            'next' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'total' => $page->total(),
        ]);
    }

    /** Formulário de avaliação a partir do link do pedido (somente quem comprou). */
    public function form(string $token)
    {
        $order = $this->reviewableOrder($token);
        $done = Review::where('order_id', $order->id)->pluck('product_id')->all();

        return view('store.review-form', [
            'order' => $order,
            'items' => $order->items->unique('product_id')->filter(fn ($i) => $i->product_id && ! in_array($i->product_id, $done)),
            'done' => count($done),
        ]);
    }

    public function store(Request $request, string $token)
    {
        $order = $this->reviewableOrder($token);
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:2000'],
            'author' => ['required', 'string', 'max:80'],
            'photos' => ['nullable', 'array', 'max:3'],
            'photos.*' => ['image', 'max:8192'],
        ], [], ['rating' => 'nota', 'body' => 'comentário', 'author' => 'nome', 'photos.*' => 'foto']);

        abort_unless($order->items->contains('product_id', (int) $data['product_id']), 422, 'Este produto não faz parte do pedido.');
        if (Review::where('order_id', $order->id)->where('product_id', $data['product_id'])->exists()) {
            return back()->with('status', 'Você já avaliou este produto. Obrigada!');
        }

        $media = [];
        foreach ($request->file('photos', []) as $file) {
            $path = $file->store('reviews', 'public');
            $media[] = ['type' => 'image', 'url' => Storage::disk('public')->url(ImageOptimizer::toWebp($path, 1200))];
        }

        $min = (int) setting('reviews.auto_approve_min', 0);
        Review::create([
            'product_id' => $data['product_id'],
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'author' => trim($data['author']),
            'email' => $order->customer_email,
            'rating' => $data['rating'],
            'body' => $data['body'] ?? null,
            'media' => $media ?: null,
            'status' => $min > 0 && $data['rating'] >= $min ? 'approved' : 'pending',
            'verified' => true,
            'source' => 'customer',
        ]);

        return back()->with('status', 'Obrigada pela sua avaliação! 💛');
    }

    public static function present(Review $r): array
    {
        return [
            'id' => $r->id,
            'author' => $r->displayName(),
            'rating' => $r->rating,
            'title' => $r->title,
            'body' => $r->body,
            'date' => $r->created_at?->format('d/m/Y'),
            // selo só para quem comprou NESTA loja (avaliações importadas nunca levam o selo)
            'verified' => $r->verified && $r->order_id !== null,
            'images' => array_column($r->images(), 'url'),
            'reply' => $r->reply,
        ];
    }

    private function reviewableOrder(string $token): Order
    {
        $order = Order::where('public_token', $token)->with('items.product.images')->firstOrFail();
        abort_unless($order->isPaid() && ! in_array($order->status, ['canceled', 'refunded'], true), 403, 'A avaliação fica disponível depois que o pedido é pago.');

        return $order;
    }
}
