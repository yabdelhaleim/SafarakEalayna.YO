<?php

namespace App\Providers;

use App\Events\TicketModified;
use App\Listeners\ProcessTicketModificationAccounting;
use App\Models\Customer;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmra\VisaAgent;
use App\Models\Flight\FlightGroup;
use App\Observers\CustomerLedgerObserver;
use App\Observers\HajjUmraExecutingCompanyObserver;
use App\Observers\UmrahSupplierObserver;
use App\Observers\VisaAgentObserver;
use App\Observers\FlightGroupObserver;
use App\Support\Finance\PostingContextRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PostingContextRegistry::class);

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
            if (! class_exists($source) && class_exists($target)) {
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

        Customer::observe(CustomerLedgerObserver::class);
        VisaAgent::observe(VisaAgentObserver::class);
        UmrahSupplier::observe(UmrahSupplierObserver::class);
        HajjUmraExecutingCompany::observe(HajjUmraExecutingCompanyObserver::class);
        FlightGroup::observe(FlightGroupObserver::class);

        Event::listen(
            TicketModified::class,
            ProcessTicketModificationAccounting::class
        );
    }
}
