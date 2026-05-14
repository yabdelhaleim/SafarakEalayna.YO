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

// SPA (Single Page Application) - Catch all routes and serve Vue app
Route::view('/{any}', 'welcome')->where('any', '.*');
