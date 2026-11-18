<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\Tracking\ContentId;
use App\Support\StoreSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * Configurações da loja em módulos: cada grupo de config/store.php vira um card;
 * o clique abre um modal só com os campos daquele módulo.
 * Segredos (tokens, chaves) são gravados criptografados e nunca reexibidos.
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Configurações da loja';

    protected static ?string $title = 'Configurações da loja';

    protected static ?string $slug = 'configuracoes';

    protected string $view = 'admin.pages.settings';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canManageStore();
    }

    /** Cards da tela: título, descrição, ícone e situação (configurado/pendente) */
    public function modules(): array
    {
        $out = [];
        foreach (config('store.groups') as $key => $g) {
            $check = $g['check'] ?? [];
            $missing = array_filter($check, fn ($k) => blank(StoreSettings::get($k)));
            $out[$key] = [
                'label' => $g['label'],
                'description' => $g['description'] ?? '',
                'icon' => $g['icon'] ?? 'heroicon-o-cog-6-tooth',
                'ok' => ! $missing,
                'status' => $check ? ($missing ? 'Pendente' : 'Configurado') : 'Padrão aplicado',
            ];
        }

        return $out;
    }

    public function editGroupAction(): Action
    {
        return Action::make('editGroup')
            ->modalHeading(fn (array $arguments) => config('store.groups.'.$arguments['group'].'.label'))
            ->modalDescription(fn (array $arguments) => config('store.groups.'.$arguments['group'].'.description'))
            ->modalIcon(fn (array $arguments) => config('store.groups.'.$arguments['group'].'.icon'))
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Salvar')
            ->schema(fn (array $arguments) => $this->fieldsFor($arguments['group']))
            ->fillForm(fn (array $arguments) => $this->valuesFor($arguments['group']))
            ->action(fn (array $data, array $arguments) => $this->saveGroup($arguments['group'], $data));
    }

    private function fieldsFor(string $group): array
    {
        $fields = [];
        foreach (config("store.groups.$group.fields", []) as $key => $f) {
            $name = self::stateKey($key);
            if (($f['type'] ?? null) === 'boolean') {
                $c = Toggle::make($name)->formatStateUsing(fn ($state) => (bool) (int) $state);
            } elseif (! empty($f['options'])) {
                $c = Select::make($name)->options($f['options'])->native(false)->selectablePlaceholder(false);
            } elseif (! empty($f['secret'])) {
                $masked = StoreSettings::masked($key);
                $c = TextInput::make($name)->password()->revealable()
                    ->placeholder($masked ? "Configurado ($masked) — deixe em branco para manter" : 'Não configurado');
            } else {
                $c = TextInput::make($name);
            }
            $fields[] = $c->label($f['label'])->helperText($f['help'] ?? null);
        }

        return $fields;
    }

    private function valuesFor(string $group): array
    {
        $values = [];
        foreach (config("store.groups.$group.fields", []) as $key => $f) {
            $values[self::stateKey($key)] = ! empty($f['secret']) ? null : StoreSettings::get($key);
        }

        return $values;
    }

    private function saveGroup(string $group, array $data): void
    {
        $values = [];
        foreach (array_keys(config("store.groups.$group.fields", [])) as $key) {
            $v = $data[self::stateKey($key)] ?? null;
            $values[$key] = is_bool($v) ? ($v ? '1' : '0') : $v;
        }
        $oldFormat = ContentId::format();
        StoreSettings::save($values);

        Setting::flushMemo();
        ContentId::reset();
        Cache::forget('layout.menus');
        Cache::forget('home.sections');
        if (ContentId::format() !== $oldFormat) {
            app(\App\Services\Tracking\ProductFeed::class)->build(); // feed precisa do novo formato de ID
        }

        Notification::make()->title(config("store.groups.$group.label").': salvo')->success()->send();
    }

    /** "nixfy.public_key" → "nixfy__public_key" (ponto no nome quebraria o caminho do formulário) */
    private static function stateKey(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
