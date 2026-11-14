<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// كل الروتات دي للـ SPA (فيلامنت بيستخدم redirectGuestsTo لو حد مش مسجل)
Route::view('/', 'welcome');
Route::view('/login', 'welcome')->name('login');
Route::view('/register', 'welcome')->name('register');

// Filament Admin Panel
// Routes are managed automatically by Filament

// API routes are handled in routes/api.php

// ─────────────────────────────────────────────────────────────────────────────
// SSO Login Route for Vue.js → Filament Admin
//
// SEC-01 / SEC-03 / SEC-04:
//   1. Validate the raw Sanctum token belongs to an active admin/owner.
//   2. Register it as a ONE-TIME nonce in the cache (60-second TTL).
//   3. The AuthenticateWithApiToken middleware consumes the nonce on first use
//      and calls session()->regenerate() to prevent session fixation.
//   4. The token never appears in /admin URL history — only in this route
//      which renders an HTML redirect page (no 302 chain) so browsers don't
//      drop Set-Cookie headers on target="_blank" SameSite restrictions.
// ─────────────────────────────────────────────────────────────────────────────
Route::get('/sso-login', function (Illuminate\Http\Request $request) {
    \Log::info('SSO ROUTE HIT', ['ip' => $request->ip()]);

    if (! $request->has('token')) {
        \Log::warning('SSO ROUTE: No token provided', ['ip' => $request->ip()]);
        return redirect('/login');
    }

    $rawToken = $request->query('token');
    $user     = \Laravel\Sanctum\PersonalAccessToken::findToken($rawToken)?->tokenable;

    if (! $user || ! $user->is_active || ! in_array($user->role, ['admin', 'owner'], true)) {
        \Log::warning('SSO ROUTE: User not found or not authorized', ['ip' => $request->ip()]);
        return redirect('/login');
    }

    // Register as a one-time SSO nonce (60-second window to open the new tab)
    $cacheKey = 'sso_nonce:' . hash('sha256', $rawToken);
    \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->addSeconds(60));

    \Log::info('SSO ROUTE: Nonce registered, rendering redirect page', [
        'user_id' => $user->id,
        'ip'      => $request->ip(),
    ]);

    // Return HTML page with JS redirect instead of 302 to avoid SameSite
    // cookie-dropping on cross-tab redirect chains.
    return response(<<<'HTML'
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>جارٍ تحميل لوحة التحكم...</title>
            <meta http-equiv="refresh" content="0; url=/admin" />
            <style>
                body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #111827; color: white; }
                .loader { border: 4px solid #374151; border-top: 4px solid #3b82f6; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin-bottom: 1rem; }
                @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            </style>
        </head>
        <body>
            <div style="text-align: center;">
                <div class="loader" style="margin: 0 auto;"></div>
                <p>جارٍ تحميل لوحة التحكم...</p>
                <script>
                    setTimeout(function() { window.location.href = '/admin'; }, 500);
                </script>
            </div>
        </body>
        </html>
        HTML
    );
})->middleware('web');

if (app()->environment('local', 'testing')) {
    Route::get('/set-session', function () {
        Auth::login(\App\Models\User::find(2));
        session(['test_val' => 'HELLO WORLD']);
        return redirect('/test-session');
    })->middleware('web');

    Route::get('/test-session', function () {
        return 'AUTH ID: ' . Auth::id() . ' | SESSION: ' . json_encode(session()->all());
    })->middleware('web');

    Route::get('/test-sso', function () {
        return 'AUTH ID: ' . Auth::id() . ' | USER: ' . json_encode(Auth::user());
    })->middleware('web');
}

// SPA (Single Page Application) - Catch all routes and serve Vue app
// Exclude /api and /admin from the catch-all to allow proper 404 handling
Route::view('/{any}', 'welcome')->where('any', '^(?!api|admin|livewire|sso-login|set-session|test-session|test-sso).*$');
