<?php

use Illuminate\Support\Facades\Route;

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

// نقاط دخول الـ SPA (أسماء المسارات للتوافق مع redirectGuestsTo و Filament إن وُجدت)
Route::view('/', 'welcome');
Route::view('/login', 'welcome')->name('login');
Route::view('/register', 'welcome')->name('register');

// Filament Admin Panel
// Routes are managed automatically by Filament

// API routes are handled in routes/api.php

// SSO Login Route for Vue.js
Route::get('/sso-login', function (Illuminate\Http\Request $request) {
    \Log::info('SSO ROUTE HIT: ' . $request->fullUrl());
    if ($request->has('token')) {
        $token = $request->query('token');
        $user = \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->tokenable;

        if ($user && $user->is_active && in_array($user->role, ['admin', 'owner'], true)) {
            Auth::login($user);
            \Log::info('SSO ROUTE: User logged in, redirecting via HTML to /admin');
            
            // Return an HTML page with JS redirect instead of 302 redirect.
            // This prevents browsers from dropping the Set-Cookie header on target="_blank" navigations
            // due to strict SameSite enforcement on cross-tab redirect chains.
            return response(<<<'HTML'
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="utf-8">
                    <title>جاري تحويلك للوحة التحكم...</title>
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
                        <p>جاري تسجيل الدخول، لحظات...</p>
                        <script>
                            setTimeout(function() { window.location.href = '/admin'; }, 500);
                        </script>
                    </div>
                </body>
                </html>
                HTML
            );
        } else {
            \Log::warning('SSO ROUTE: User not found or not authorized');
        }
    } else {
        \Log::warning('SSO ROUTE: No token provided');
    }
    return redirect('/login');
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
