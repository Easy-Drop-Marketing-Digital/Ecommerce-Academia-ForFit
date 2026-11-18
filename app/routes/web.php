<?php

use App\Http\Controllers\Store\AccountController;
use App\Http\Controllers\Store\CartController;
use App\Http\Controllers\Store\CheckoutController;
use App\Http\Controllers\Store\OrderStatusController;
use App\Http\Controllers\Store\CollectionController;
use App\Http\Controllers\Store\HomeController;
use App\Http\Controllers\Store\PageController;
use App\Http\Controllers\Store\ProductController;
use App\Http\Controllers\Store\SeoController;
use App\Http\Controllers\Webhooks\NixfyWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Vitrine — URLs iguais às da Shopify (/collections, /products, /pages, /policies, /cart, /search)
| para não perder indexação no Google nem quebrar links de anúncios ativos.
*/
Route::get('/', HomeController::class)->middleware('page.cache')->name('home');
Route::get('/collections', fn () => redirect()->route('category.show', 'all'));
Route::get('/collections/{slug}', [CollectionController::class, 'show'])->middleware('page.cache')->name('category.show');
Route::get('/collections/{collection}/products/{slug}', fn ($c, $slug) => redirect()->route('product.show', $slug, 301));
Route::get('/products/{slug}', [ProductController::class, 'show'])->middleware('page.cache')->name('product.show');
Route::get('/products/{slug}/avaliacoes', [\App\Http\Controllers\Store\ReviewController::class, 'index'])->middleware('throttle:120,1')->name('reviews.index');
Route::get('/avaliar/{token}', [\App\Http\Controllers\Store\ReviewController::class, 'form'])->whereUuid('token')->name('reviews.form');
Route::post('/avaliar/{token}', [\App\Http\Controllers\Store\ReviewController::class, 'store'])->whereUuid('token')->middleware('throttle:20,1')->name('reviews.store');
Route::get('/marcas/{slug}', [CollectionController::class, 'brand'])->middleware('page.cache')->name('brand.show');
Route::get('/search', [CollectionController::class, 'search'])->name('search');
Route::get('/pages/{slug}', [PageController::class, 'page'])->middleware('page.cache')->name('page.show');
Route::get('/policies/{slug}', [PageController::class, 'policy'])->middleware('page.cache')->name('policy.show');

// Carrinho (gaveta usa os endpoints JSON)
Route::get('/cart', [CartController::class, 'show'])->name('cart.show');
Route::get('/cart.json', [CartController::class, 'json'])->name('cart.json');
Route::post('/cart/add', [CartController::class, 'add'])->middleware('throttle:60,1')->name('cart.add');
Route::post('/cart/update', [CartController::class, 'update'])->middleware('throttle:120,1')->name('cart.update');
Route::get('/cart/recover/{token}', [CartController::class, 'recover'])->name('cart.recover');
// Links no formato da Shopify usados em anúncios, WhatsApp e influenciadoras
Route::get('/cart/{items}', [CartController::class, 'permalink'])->where('items', '[0-9]+(:[0-9]+)?(,[0-9]+(:[0-9]+)?)*')->middleware('throttle:30,1')->name('cart.permalink');
Route::get('/discount/{code}', [CartController::class, 'discountLink'])->where('code', '[A-Za-z0-9_\-]{1,60}')->middleware('throttle:30,1')->name('discount.link');

// Rastreamento (beacon do navegador → analytics próprio + API de Conversões)
Route::post('/t', \App\Http\Controllers\TrackController::class)->middleware('throttle:240,1')->name('track');

// Descadastro de e-mails de marketing (link assinado em cada e-mail; também aceita POST de 1 clique do Gmail)
Route::match(['get', 'post'], '/email/descadastrar', function (\Illuminate\Http\Request $request) {
    $email = mb_strtolower((string) $request->query('email'));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        \App\Models\EmailSuppression::suppress($email);
    }

    return $request->isMethod('post') ? response()->noContent() : view('store.unsubscribed', ['email' => $email]);
})->middleware('signed')->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)->name('email.unsubscribe');

// SEO
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots']);
Route::get('/feeds/produtos.xml', [\App\Http\Controllers\Store\FeedController::class, 'products'])->name('feed.products');

// Checkout próprio
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');
Route::post('/checkout/quote', [CheckoutController::class, 'quote'])->middleware('throttle:120,1')->name('checkout.quote');
Route::post('/checkout/capture', [CheckoutController::class, 'capture'])->middleware('throttle:60,1')->name('checkout.capture');
Route::post('/checkout', [CheckoutController::class, 'place'])->middleware('throttle:checkout')->name('checkout.place');

// Pedido (link público por token)
Route::get('/pedido/{token}', [OrderStatusController::class, 'show'])->whereUuid('token')->name('order.status');
Route::get('/pedido/{token}/status', [OrderStatusController::class, 'status'])->whereUuid('token')->middleware('throttle:60,1')->name('order.status.json');
Route::post('/pedido/{token}/pagar', [OrderStatusController::class, 'retry'])->whereUuid('token')->middleware('throttle:checkout')->name('order.retry');
Route::post('/rastrear', [OrderStatusController::class, 'track'])->middleware('throttle:20,1')->name('order.track');

// Minha conta
Route::prefix('account')->name('account.')->group(function () {
    Route::middleware('guest:customer')->group(function () {
        Route::get('/login', [AccountController::class, 'loginForm'])->name('login');
        Route::post('/login', [AccountController::class, 'login'])->middleware('throttle:10,1');
        Route::get('/register', [AccountController::class, 'registerForm'])->name('register');
        Route::post('/register', [AccountController::class, 'register'])->middleware('throttle:10,1');
        Route::get('/recuperar-senha', [AccountController::class, 'forgotForm'])->name('password.request');
        Route::post('/recuperar-senha', [AccountController::class, 'forgot'])->middleware('throttle:5,1')->name('password.email');
        Route::get('/nova-senha/{token}', [AccountController::class, 'resetForm'])->name('password.reset');
        Route::post('/nova-senha', [AccountController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');
    });
    Route::middleware('auth:customer')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->name('index');
        Route::get('/pedidos/{number}', [AccountController::class, 'order'])->name('order');
        Route::post('/logout', [AccountController::class, 'logout'])->name('logout');
    });
});

// Webhooks Nixfy (sem CSRF, validados por HMAC + reconsulta na API)
Route::post('/webhooks/nixfy', [NixfyWebhookController::class, 'webhook'])->name('webhooks.nixfy');
Route::post('/webhooks/nixfy/postback', [NixfyWebhookController::class, 'postback'])->name('webhooks.nixfy.postback');

// Sandbox local da Nixfy (só existe fora de produção): simula a confirmação de um PIX
if (! app()->isProduction()) {
    Route::get('/dev/sandbox/pagar/{uuid}', function (string $uuid) {
        abort_unless(config('services.nixfy.sandbox'), 404);
        \Illuminate\Support\Facades\Cache::put("sandbox.tx.$uuid", 'PAGO', now()->addDay());
        $order = \App\Models\Order::where('gateway_uuid', $uuid)->firstOrFail();

        return redirect($order->publicUrl());
    })->name('dev.sandbox.pay');
}
