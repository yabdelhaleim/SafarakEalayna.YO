<?php

namespace App\Traits;

use App\Helpers\CacheHelper;

trait ClearsCache
{
    protected static function bootClearsCache(): void
    {
        $clearCache = function ($model): void {
            CacheHelper::flushTags([$model->getTable(), 'dashboard']);
        };

        static::saved($clearCache);
        static::deleted($clearCache);
    }
}
