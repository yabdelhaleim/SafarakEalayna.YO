<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;

class CacheHelper
{
    /**
     * Get a cache store that supports tagging if available, or fall back to the default store.
     */
    public static function tags(array $tags)
    {
        if (method_exists(Cache::getStore(), 'tags')) {
            return Cache::tags($tags);
        }

        // Return a proxy that ignores tags and delegates to global Cache
        return new class {
            public function remember(string $key, $ttl, \Closure $callback)
            {
                return Cache::remember($key, $ttl, $callback);
            }
        };
    }
}
