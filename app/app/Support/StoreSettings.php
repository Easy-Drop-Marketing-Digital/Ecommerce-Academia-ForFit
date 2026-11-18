<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Arr;

/**
 * Ponte entre as configurações editáveis no admin (tabela settings) e a config do Laravel.
 *
 * - applyToConfig(): roda no boot; campos com 'maps_to' sobrescrevem config() quando preenchidos.
 * - get(): valor do banco → padrão definido em config/store.php → valor atual da config (.env).
 * - save(): grava (criptografando segredos) e limpa o cache. Vale na próxima requisição, sem deploy.
 */
class StoreSettings
{
    public static function fields(): array
    {
        $out = [];
        foreach (config('store.groups', []) as $group => $g) {
            foreach ($g['fields'] as $key => $f) {
                $out[$key] = $f + ['group' => $group];
            }
        }

        return $out;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $field = static::fields()[$key] ?? [];
        $value = static::fromDb($key);
        if ($value !== null && $value !== '') {
            return $value;
        }
        if (isset($field['maps_to']) && ($cfg = config($field['maps_to'])) !== null && $cfg !== '') {
            return $cfg;
        }

        return $field['default'] ?? $default;
    }

    public static function save(array $values): void
    {
        $fields = static::fields();
        foreach ($values as $key => $value) {
            if (! isset($fields[$key])) {
                continue;
            }
            $secret = ! empty($fields[$key]['secret']);
            // Segredo enviado em branco = manter o atual (a tela nunca reexibe o valor)
            if ($secret && ($value === null || $value === '')) {
                continue;
            }
            Setting::set($key, is_string($value) ? trim($value) : $value, encrypt: $secret);
        }
    }

    /** Valor mascarado para exibir segredos no admin: ••••1234 */
    public static function masked(string $key): ?string
    {
        $v = (string) static::fromDb($key);

        return $v === '' ? null : '••••'.substr($v, -4);
    }

    public static function applyToConfig(): void
    {
        // Sem consulta extra por requisição: Setting::get usa cache. Se a tabela ainda não existir
        // (antes da primeira migration) ou o banco cair, fromDb() devolve null e vale o .env.
        foreach (static::fields() as $key => $f) {
            if (empty($f['maps_to'])) {
                continue;
            }
            $v = static::fromDb($key);
            if ($v !== null && $v !== '') {
                config([$f['maps_to'] => $v]);
            }
        }
    }

    private static function fromDb(string $key): mixed
    {
        try {
            $v = Setting::get($key);
        } catch (\Throwable) {
            return null;
        }

        return is_scalar($v) ? (string) $v : (is_null($v) ? null : Arr::wrap($v));
    }
}
