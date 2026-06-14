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
        // Disabled Sanctum stateful API to allow pure token-based auth for SPA
        // $middleware->statefulApi();

        $middleware->web(append: [
            \App\Http\Middleware\AuthenticateWithApiToken::class,
        ]);

        $middleware->api(append: [
            \App\Http\Middleware\StandardizeApiResponse::class,
        ]);

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
        // Report exceptions to log and potentially notify admin
        $exceptions->report(function (Throwable $e) {
            // If it's a critical error and we're in production, we could send a notification
            // but for now, we just ensure it's logged properly without leaking to UI.
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            $isApiPath = $request->is('api/*') || $request->expectsJson();
            $isFilamentPath = $request->is('admin/*');

            // Helper to get friendly messages
            $getFriendlyMessage = function (Throwable $e, int $statusCode) {
                if ($e instanceof ValidationException) {
                    return 'بيانات المدخلات غير صالحة.';
                }

                if (config('app.debug')) {
                    return $e->getMessage();
                }

                return match ($statusCode) {
                    401 => 'غير مصرح لك بالوصول. يرجى تسجيل الدخول.',
                    403 => 'ليس لديك الصلاحية للقيام بهذا الإجراء.',
                    404 => 'المورد المطلوب غير موجود.',
                    405 => 'طريقة الطلب غير مسموح بها.',
                    419 => 'انتهت صلاحية الجلسة، يرجى إعادة المحاولة.',
                    422 => 'بيانات المدخلات غير صالحة.',
                    429 => 'طلبات كثيرة جداً، يرجى المحاولة لاحقاً.',
                    500 => 'حدث خطأ داخلي في الخادم، يرجى المحاولة لاحقاً.',
                    503 => 'الخدمة غير متوفرة حالياً (صيانة).',
                    default => 'حدث خطأ غير متوقع في الخادم، يرجى المحاولة لاحقاً.',
                };
            };

            // Handle API / JSON requests
            if ($isApiPath) {
                $statusCode = 500;
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    $statusCode = $e->getStatusCode();
                } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    $statusCode = 401;
                } elseif ($e instanceof \Illuminate\Validation\ValidationException) {
                    $statusCode = 422;
                } elseif ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException || $e instanceof NotFoundHttpException) {
                    $statusCode = 404;
                } elseif ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                    $statusCode = 403;
                }

                if ($statusCode >= 500) {
                    file_put_contents('C:\laragon\tmp\passenger_error.log', 
                        "Timestamp: " . date('Y-m-d H:i:s') . "\n" .
                        "CRITICAL API ERROR: " . $e->getMessage() . "\n" .
                        "Exception: " . get_class($e) . "\n" .
                        "Path: " . $request->fullUrl() . "\n" .
                        "User ID: " . (auth()->id() ?? 'guest') . "\n" .
                        "Payload: " . json_encode($request->all()) . "\n" .
                        "Trace:\n" . $e->getTraceAsString() . "\n",
                        FILE_APPEND
                    );
                    \Log::critical('CRITICAL API ERROR: ' . $e->getMessage(), [
                        'exception' => get_class($e),
                        'path' => $request->fullUrl(),
                        'user_id' => auth()->id() ?? 'guest',
                        'payload' => $request->all(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }

                $response = [
                    'success' => false,
                    'message' => $getFriendlyMessage($e, $statusCode),
                    'data' => null,
                    'errors' => null,
                ];

                if ($e instanceof ValidationException) {
                    $response['errors'] = $e->errors();
                }

                return response()->json($response, $statusCode);
            }

            // Handle Filament Exceptions (Livewire)
            if ($isFilamentPath && !config('app.debug')) {
                // For non-JSON filament requests, we still want to avoid raw errors.
                // Filament/Livewire usually handles this, but if it bubbles up here:
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return null; // Let Filament handle validation
                }
                
                // If it's a 404/403, Laravel's default handler will use our custom views.
                // But for 500, we want to ensure it looks professional.
            }

            return null;
        });
    })->create();
