<?php

namespace App\Providers\Filament;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->passwordReset()
            ->profile(isSimple: false)
            // Verificação em duas etapas com app autenticador. Obrigatória em produção: quem entra no admin
            // pode trocar as chaves da Nixfy (para onde vai o dinheiro). ADMIN_REQUIRE_2FA=false desliga.
            ->multiFactorAuthentication(
                // nome que aparece no app autenticador (Google Authenticator etc.) — vem do APP_NAME do .env
                [AppAuthentication::make()->recoverable()->brandName(config('app.name').' Admin')],
                isRequired: true, // a exigência real é decidida por acesso no RequireAdminTwoFactor
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(\App\Http\Middleware\RequireAdminTwoFactor::class)
            ->brandName(fn () => setting('store.name').' · Admin')
            ->brandLogo(fn () => ($logo = setting('store.logo')) ? img_url($logo) : null)
            ->brandLogoHeight('1.15rem') // logo horizontal (palavra única)
            ->favicon(asset('favicon.ico'))
            ->darkMode(false) // admin sempre claro: branco com detalhes pretos
            ->colors([
                // Preto como cor principal (botões, links, item ativo); verde fica só para status de sucesso
                'primary' => [50 => '#f7f7f7', 100 => '#eeeeee', 200 => '#dcdcdc', 300 => '#bdbdbd', 400 => '#7a7a7a', 500 => '#2b2b2b', 600 => '#111111', 700 => '#0a0a0a', 800 => '#050505', 900 => '#000000', 950 => '#000000'],
                'gray' => Color::Zinc,
                'success' => Color::Green,
                'warning' => Color::Amber,
                'danger' => Color::Red,
                'info' => Color::Sky,
            ])
            ->font('Montserrat')
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->navigationGroups([
                NavigationGroup::make('Vendas'),
                NavigationGroup::make('Catálogo'),
                NavigationGroup::make('Marketing'),
                NavigationGroup::make('Loja online'),
                NavigationGroup::make('Configurações')->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
