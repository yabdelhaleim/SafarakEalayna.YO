<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CacheHelper
{
    /**
     * Determine whether the configured cache store supports cache tags.
     */
    public static function supportsTags(?object $store = null): bool
    {
        $store ??= Cache::getStore();

        return method_exists($store, 'tags');
    }

    /**
     * Flush cache tags when the configured store supports them.
     *
     * Database and file stores do not support tags. They must not fall back to
     * Cache::flush(), because that deletes every cache entry and can block the
     * same database connection used by a financial transaction.
     */
    public static function flushTags(array $tags): void
    {
        try {
            if (! self::supportsTags()) {
                return;
            }

            Cache::tags($tags)->flush();
        } catch (Throwable $e) {
            // Cache invalidation is best-effort. Never turn a successful
            // financial write into a failed request because the cache is down.
            Log::warning('Cache tag invalidation failed', [
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get a cache store that supports tagging if available, or fall back to the default store.
     */
    public static function tags(array $tags)
    {
        if (self::supportsTags()) {
            return Cache::tags($tags);
        }

        // Return a proxy that ignores tags and delegates to global Cache
        return new class
        {
            public function remember(string $key, $ttl, \Closure $callback)
            {
                return Cache::remember($key, $ttl, $callback);
            }
        };
    }
}
