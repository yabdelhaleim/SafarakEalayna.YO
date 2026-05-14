<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureIsActive;
use App\Http\Middleware\EnsureIsAdmin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Database\QueryException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable Sanctum stateful API for SPA token authentication
        $middleware->statefulApi();

        $middleware->alias([
            'admin' => EnsureIsAdmin::class,
            'active' => EnsureIsActive::class,
            'role' => EnsureIsAdmin::class,
            'permission' => CheckPermission::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('ledger:reconcile')->dailyAt('03:10');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            // Only handle API requests with JSON responses.
            // Some clients (or tools) may omit the Accept: application/json header.
            // For any /api/* path, always return JSON to avoid SPA HTML fallbacks.
            $isApiPath = $request->is('api/*');

            if (! $request->expectsJson() && ! $isApiPath) {
                return null;
            }

            // Case 1: AuthenticationException
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'غير مصرح لك بالوصول.',
                    'data' => null,
                    'errors' => null,
                ], 401);
            }

            // Case 2: ValidationException
            if ($e instanceof ValidationException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'فشل التحقق من صحة البيانات.',
                    'data' => null,
                    'errors' => $e->errors(),
                ], 422);
            }

            // Case 3: ModelNotFoundException
            if ($e instanceof NotFoundHttpException && $e->getPrevious() instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                $modelException = $e->getPrevious();
                $modelName = last(explode('\\', $modelException->getModel()));

                return response()->json([
                    'status' => 'error',
                    'message' => 'السجل المطلوب غير موجود.',
                    'data' => null,
                    'errors' => null,
                ], 404);
            }

            // Case 4: QueryException
            if ($e instanceof QueryException) {
                \Log::error('Database query error', [
                    'message' => $e->getMessage(),
                    'sql' => $e->getSql(),
                ]);

                if (config('app.debug')) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Database query error: ' . $e->getMessage(),
                        'data' => null,
                        'errors' => [
                            'sql' => $e->getSql(),
                            'bindings' => $e->getBindings(),
                        ],
                    ], 500);
                }

                return response()->json([
                    'status' => 'error',
                    'message' => 'حدث خطأ في قاعدة البيانات، يرجى المحاولة لاحقًا.',
                    'data' => null,
                    'errors' => null,
                ], 500);
            }

            // Case 5: General Exception (catch-all)
            \Log::error('Unhandled exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (config('app.debug')) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'data' => null,
                    'errors' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => collect($e->getTrace())->take(10)->toArray(),
                    ],
                ], 500);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'حدث خطأ غير متوقع.',
                'data' => null,
                'errors' => null,
            ], 500);
        });
    })->create();
