<?php

namespace App\Models\Concerns;

use App\Support\PageCache;

/** Ao mudar no admin algo que aparece na vitrine, limpa o cache de página. */
trait FlushesPageCache
{
    protected static function bootFlushesPageCache(): void
    {
        static::saved(function ($model) {
            if ($model->wasRecentlyCreated || $model->wasChanged()) {
                PageCache::flush();
            }
        });
        static::deleted(fn () => PageCache::flush());
    }
}
