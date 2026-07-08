<?php

namespace App\Providers;

use App\Events\TicketModified;
use App\Listeners\ProcessTicketModificationAccounting;
use App\Models\Customer;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmra\VisaAgent;
use App\Models\Flight\FlightGroup;
use App\Models\User;
use App\Notifications\BalanceTamperDetectedNotification;
use App\Observers\CustomerLedgerObserver;
use App\Observers\HajjUmraExecutingCompanyObserver;
use App\Observers\UmrahSupplierObserver;
use App\Observers\VisaAgentObserver;
use App\Observers\FlightGroupObserver;
use App\Support\Finance\LedgerBalanceMutationGuard;
use App\Support\Finance\PostingContextRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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

        // ════════════════════════════════════════════════════════════════════
        // SAFETY NET (Phase 1 + 1v2): كشف أي UPDATE مباشر على flight_carriers.balance
        // أو flight_systems.balance أو airline_accounts.balance يتجاوز الـ Eloquent observer.
        //
        // الـ FlightCarrier::updating() observer يحمي من التعديل عبر Eloquent.
        // لكن DB::table('flight_carriers')->update(['balance' => ...]) يتخطاه.
        //
        // نضيف DB::listen() يرصد أي UPDATE على عمود balance في الـ 3 جداول المحمية
        // خارج سياق LedgerBalanceMutationGuard::run() — ويسجل Log::warning فقط
        // (لا يرمي exception عشان ما يكسرش migrations / seeders / artisan commands).
        //
        // @see app/Models/Flight/FlightCarrier.php
        // @see app/Models/Flight/FlightSystem.php
        // @see app/Models/Flight/AirlineAccount.php  ← Phase 1v2
        // @see app/Services/Flight/FlightCarrierRechargeService.php (الطريق المعتمد)
        // ════════════════════════════════════════════════════════════════════
        Event::listen(QueryExecuted::class, function (QueryExecuted $event): void {
            $sql = strtolower(trim($event->sql));

            // فقط استعلامات UPDATE على الـ 3 جداول المحمية
            if (! str_starts_with($sql, 'update')) {
                return;
            }
            $touchingCarrier  = str_contains($sql, 'flight_carriers')  && str_contains($sql, 'balance');
            $touchingSystem   = str_contains($sql, 'flight_systems')   && str_contains($sql, 'balance');
            $touchingAirline  = str_contains($sql, 'airline_accounts') && str_contains($sql, 'balance');
            if (! $touchingCarrier && ! $touchingSystem && ! $touchingAirline) {
                return;
            }

            // استثناء: Migrations و seeders الرسمية (الـ connection name المُحدّد)
            $connection = $event->connectionName ?? '';
            if (in_array($connection, ['migrations_internal', 'system_seed'], true)) {
                return;
            }

            // لو جوه مسار LedgerBalanceMutationGuard::run() → مسموح
            if (LedgerBalanceMutationGuard::isAllowed()) {
                return;
            }

            // تتبع الـ stack trace لمعرفة المتصل
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            $caller = collect($trace)
                ->first(fn ($f) => isset($f['file']) && ! str_contains($f['file'], '/vendor/'))
                ?? [];

            $tableName = match (true) {
                $touchingCarrier => 'flight_carriers',
                $touchingSystem  => 'flight_systems',
                $touchingAirline => 'airline_accounts',
                default          => 'unknown',
            };
            $sqlPreview = mb_substr($event->sql, 0, 200);
            $callerFile = $caller['file'] ?? '?';
            $callerLine = (int) ($caller['line'] ?? 0);

            // ───────────────────────────────────────────────────────────────
            // ① Log warning (لا يكسر الـ request)
            // ───────────────────────────────────────────────────────────────
            Log::warning('⚠️ Direct DB UPDATE on protected balance column detected', [
                'table' => $tableName,
                'sql_preview' => $sqlPreview,
                'caller_file' => $callerFile,
                'caller_line' => $callerLine,
                'binding_count' => count($event->bindings ?? []),
                'connection' => $connection,
                'user_id' => Auth::id(),
                'hint' => 'استخدم FlightCarrierRechargeService::rechargeFromAccount() أو AirlineAccountDebitService أو debit()/credit() بدلاً من ذلك.',
            ]);

            // ───────────────────────────────────────────────────────────────
            // ② Critical notification لكل الأدمن (Filament database + email)
            // ───────────────────────────────────────────────────────────────
            try {
                $admins = User::where('role', 'admin')
                    ->where('is_active', true)
                    ->get();

                if ($admins->isNotEmpty()) {
                    $userIdentifier = Auth::user()?->email
                        ?? Auth::user()?->name
                        ?? 'CLI/Background';

                    $notification = new BalanceTamperDetectedNotification(
                        table: $tableName,
                        sqlPreview: $sqlPreview,
                        callerFile: $callerFile,
                        callerLine: $callerLine,
                        userIdentifier: $userIdentifier,
                        connectionName: $connection,
                    );

                    Notification::send($admins, $notification);
                }
            } catch (\Throwable $e) {
                // لو فشل إرسال الـ notification، سجّل بس ما تكسرش الـ request
                Log::error('Failed to send BalanceTamperDetectedNotification', [
                    'notification_error' => $e->getMessage(),
                ]);
            }
        });
    }
}
