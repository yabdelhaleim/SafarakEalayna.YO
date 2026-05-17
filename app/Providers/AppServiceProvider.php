<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Support\Finance\PostingContextRegistry::class);

        $aliases = [
            'Action',
            'BulkActionGroup',
            'DeleteAction',
            'DeleteBulkAction',
            'EditAction',
            'ForceDeleteAction',
            'ForceDeleteBulkAction',
            'RestoreAction',
            'RestoreBulkAction',
            'ViewAction',
            'ExportBulkAction',
            'ActionGroup',
            'BulkAction',
            'CreateAction',
            'ImportAction',
            'ExportAction',
        ];

        foreach ($aliases as $alias) {
            $target = "Filament\\Actions\\{$alias}";
            $source = "Filament\\Tables\\Actions\\{$alias}";
            if (!class_exists($source) && class_exists($target)) {
                class_alias($target, $source);
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        \App\Models\Customer::observe(\App\Observers\CustomerLedgerObserver::class);

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\TicketModified::class,
            \App\Listeners\ProcessTicketModificationAccounting::class
        );
    }
}
