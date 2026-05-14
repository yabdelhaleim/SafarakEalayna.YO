<?php

use App\Http\Controllers\Api\V1\Finance\AccountController;
use App\Http\Controllers\Api\V1\Finance\SupplierAccountController;
use App\Http\Controllers\Api\V1\SupplierController;
use Illuminate\Support\Facades\Route;

Route::prefix('finance')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [AccountController::class, 'index']);
    Route::post('/', [AccountController::class, 'store']);
    Route::get('/{id}', [AccountController::class, 'show']);
    Route::put('/{id}', [AccountController::class, 'update']);
    Route::patch('/{id}/deactivate', [AccountController::class, 'deactivate']);
    Route::get('/{id}/statement', [AccountController::class, 'statement']);
    Route::post('/transfers', [AccountController::class, 'transfer']);
});

Route::prefix('suppliers')->middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/{supplier}/account/recharge', [SupplierAccountController::class, 'recharge']);
    Route::post('/{supplier}/account/recharge', [SupplierAccountController::class, 'recharge']);
    Route::get('/{supplier}/account/statement', [SupplierAccountController::class, 'statement']);
    Route::get('/{supplier}/account/balance', [SupplierAccountController::class, 'balance']);
});
