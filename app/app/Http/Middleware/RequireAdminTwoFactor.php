<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Illuminate\Http\Request;

/**
 * Verificação em duas etapas obrigatória no admin, decidida a cada acesso por config('store.admin_require_2fa')
 * (o middleware do Filament é fixado na hora de registrar as rotas e ficaria preso no route:cache).
 */
class RequireAdminTwoFactor extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('store.admin_require_2fa')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
