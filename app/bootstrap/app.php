<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Webhooks de gateway não carregam token CSRF (validados por HMAC)
        $middleware->validateCsrfTokens(except: ["webhooks/*", "t"]); // /t = beacon de eventos (sendBeacon não envia cabeçalhos)
        // Hostinger/Cloudflare na frente: confia no proxy para IP real da cliente (usado no Meta CAPI)
        $middleware->trustProxies(at: "*");
        // Cabeçalhos de segurança em tudo (loja, checkout, admin, webhooks)
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Cache de página da vitrine (Black Friday): rotas com ->middleware('page.cache')
        $middleware->alias(['page.cache' => \App\Http\Middleware\CachePage::class]);
        // Origem da visita (UTMs, fbclid...) para atribuição de pedidos e Meta CAPI
        $middleware->web(append: [\App\Http\Middleware\CaptureAttribution::class, \App\Http\Middleware\VisitorCookies::class]);
        // Cookies do Meta Pixel precisam ficar legíveis pelo JavaScript do Pixel
        $middleware->encryptCookies(except: ["_fbc", "_fbp", "_ttp", "_ga"]);
        // Login da cliente (Minha conta)
        $middleware->redirectGuestsTo(fn () => route("account.login"));
        $middleware->redirectUsersTo(fn () => route("account.index"));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Dados de cartão nunca voltam para a sessão/old input em caso de erro
        $exceptions->dontFlash(["card_number", "card_cvv", "card_exp", "card_holder", "password", "password_confirmation"]);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Página não existe: se houver redirecionamento cadastrado (Admin → Redirecionamentos), leva ao destino com 301
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if ($request->isMethod('GET') && ! $request->is('admin', 'admin/*', 'livewire*') && $to = \App\Models\Redirect::lookup($request->path())) {
                $query = $request->getQueryString();

                return redirect()->to($to.($query && ! str_contains($to, '?') ? '?'.$query : ''), 301);
            }
        });
    })->create();
