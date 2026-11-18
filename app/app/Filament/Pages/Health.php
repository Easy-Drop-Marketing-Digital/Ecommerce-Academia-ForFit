<?php

namespace App\Filament\Pages;

use App\Support\PageCache;
use App\Support\SystemHealth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Saúde da loja: cron, pagamentos, e-mails, pixels, fotos, cache, servidor, erros, segurança. */
class Health extends Page
{
    protected string $view = 'admin.pages.health';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Saúde da loja';

    protected static ?string $title = 'Saúde da loja';

    protected static ?string $slug = 'saude';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canManageStore();
    }

    public static function getNavigationBadge(): ?string
    {
        $n = count(SystemHealth::critical());

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getSubheading(): ?string
    {
        return 'Tudo o que precisa estar funcionando para vender. Atualiza ao abrir a página.';
    }

    public function checks(): array
    {
        return SystemHealth::checks(fresh: true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('flushCache')
                ->label('Limpar cache da loja')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('As páginas são montadas de novo nas próximas visitas. Use se algo alterado não apareceu na loja.')
                ->action(function () {
                    PageCache::flush();
                    cache()->forget('layout.menus');
                    cache()->forget('home.sections');
                    // telas (.blade.php) editadas pelo Gerenciador de Arquivos passam a valer sem SSH
                    \Illuminate\Support\Facades\Artisan::call('view:clear');
                    Notification::make()->title('Cache da loja limpo')->success()->send();
                }),
        ];
    }
}
