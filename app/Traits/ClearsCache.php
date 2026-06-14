<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;

trait ClearsCache
{
    protected static function bootClearsCache(): void
    {
        $clearCache = function ($model): void {
            $tag = $model->getTable();
            try {
                Cache::tags([$tag, 'dashboard'])->flush();
            } catch (\Exception $e) {
                Cache::flush();
            }
        };

        static::saved($clearCache);
        static::deleted($clearCache);
    }
}
