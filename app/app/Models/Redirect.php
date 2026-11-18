<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Redirecionamento 301: usado quando a página pedida não existe (404). */
class Redirect extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['last_hit_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(fn (Redirect $r) => $r->from_path = self::normalize($r->from_path));
        static::saved(fn () => Cache::forget('redirects.map'));
        static::deleted(fn () => Cache::forget('redirects.map'));
    }

    /** "/Products/Vestido-X/?a=1" → "/products/vestido-x" */
    public static function normalize(?string $path): string
    {
        $path = parse_url(trim((string) $path), PHP_URL_PATH) ?: '/';

        return '/'.trim(mb_strtolower(rawurldecode($path)), '/');
    }

    public static function lookup(string $path): ?string
    {
        $map = Cache::rememberForever('redirects.map', fn () => static::pluck('to_url', 'from_path')->all());
        $from = self::normalize($path);
        if (! isset($map[$from])) {
            return null;
        }
        static::where('from_path', $from)->update(['hits' => \DB::raw('hits + 1'), 'last_hit_at' => now()]);

        return $map[$from];
    }

    /** Produto/coleção mudou de endereço: o antigo continua levando ao novo (anúncios e Google) */
    public static function moved(string $old, string $new): void
    {
        $old = self::normalize($old);
        $new = self::normalize($new);
        if ($old === $new) {
            return;
        }
        static::where('from_path', $new)->delete();                       // evita laço
        static::where('to_url', $old)->update(['to_url' => $new]);         // encurta cadeias A→B→C
        static::updateOrCreate(['from_path' => $old], ['to_url' => $new]);
    }
}
