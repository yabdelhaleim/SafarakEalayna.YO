<?php

namespace App\Traits;

use App\Helpers\CacheHelper;

trait ClearsCache
{
    /**
     * Boot the ClearsCache trait — wires automatic cache invalidation to
     * the Eloquent `saved` and `deleted` events for the model that uses
     * this trait.
     *
     * Behaviour:
     *  - If the cache store supports tags, we flush the model's table as
     *    a cache tag.
     *  - If not (e.g. the `database` driver that ships with this project),
     *    we fall back to dropping every key under
     *    `CacheHelper::FINANCE_LISTING_NAMESPACE` (a "namespace flush" —
     *    no `Cache::flush()` needed so we don't break concurrent reads).
     */
    protected static function bootClearsCache(): void
    {
        $clearCache = function ($model): void {
            // Drop the financial-listing namespace so any cached
            // AccountController::index() / /reports/* listings instantly
            // reflect the new / updated / deleted row.  Also flush the
            // model's table as a tag (only used when the store supports
            // tagging) for wider compatibility.
            CacheHelper::flushTags([$model->getTable(), 'dashboard']);
            // Belt-and-suspenders: drop the namespace directly even when
            // tags are unsupported.
            CacheHelper::flushNamespace();
        };

        static::saved($clearCache);
        static::deleted($clearCache);
    }
}
