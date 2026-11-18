<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Health;
use App\Support\SystemHealth;
use Filament\Widgets\Widget;

/** Faixa vermelha no topo do Painel quando algo crítico está parado (só aparece se houver problema). */
class HealthAlert extends Widget
{
    protected string $view = 'admin.widgets.health-alert';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    // aparece já no carregamento (não espera rolar a página)
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canManageStore() && SystemHealth::critical() !== [];
    }

    protected function getViewData(): array
    {
        return ['problems' => SystemHealth::critical(), 'url' => Health::getUrl()];
    }
}
