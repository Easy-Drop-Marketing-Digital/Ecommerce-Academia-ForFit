<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Configurações da loja editáveis no admin (chave/valor, com cache).
 * Uso: Setting::get('store.name'), Setting::set('meta.capi_token', $t, encrypt: true)
 */
class Setting extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Memória por requisição: evita ler o cache a cada chamada de setting() */
    private static ?array $memo = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::$memo ??= Cache::rememberForever('settings.all', fn () => static::all()->mapWithKeys(fn ($s) => [
            $s->key => $s->is_encrypted && $s->value ? Crypt::decryptString($s->value) : $s->value,
        ])->all());

        if (! array_key_exists($key, $all) || $all[$key] === null) {
            return $default;
        }
        $decoded = json_decode($all[$key], true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $all[$key];
    }

    public static function set(string $key, mixed $value, bool $encrypt = false): void
    {
        $raw = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        static::updateOrCreate(['key' => $key], [
            'value' => $encrypt && $raw !== null ? Crypt::encryptString($raw) : $raw,
            'is_encrypted' => $encrypt,
        ]);
        Cache::forget('settings.all');
        self::$memo = null;
    }

    public static function flushMemo(): void
    {
        self::$memo = null;
    }
}
