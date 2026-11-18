<?php

namespace App\Providers;

use App\Listeners\OrderLifecycle;
use App\Support\StoreSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use App\View\Composers\StoreLayoutComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Chaves/tokens/remetente editados no admin passam a valer sem mexer no .env
        StoreSettings::applyToConfig();

        // Sem chave da Resend fora de produção: e-mails vão para o log (storage/logs) em vez de falhar
        if (config('mail.default') === 'resend' && ! config('services.resend.key') && ! $this->app->isProduction()) {
            config(['mail.default' => 'log']);
        }

        View::composer(['layouts.store', 'store.collection'], StoreLayoutComposer::class); // coleção usa o menu no filtro lateral

        Event::subscribe(OrderLifecycle::class);
        Event::subscribe(\App\Listeners\TrackingListener::class);
        Event::subscribe(\App\Listeners\EmailListener::class);

        // Finalizar compra: limita tentativas por IP (protege contra robôs testando cartões)
        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinute(8)->by('checkout:'.$request->ip()),
            Limit::perHour(40)->by('checkout-h:'.$request->ip()),
        ]);
    }
}
