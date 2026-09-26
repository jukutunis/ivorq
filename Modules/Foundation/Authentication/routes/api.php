<?php

use Illuminate\Support\Facades\Route;
use Modules\Foundation\Authentication\Http\Controllers\LoginController;
use Modules\Foundation\Authentication\Http\Controllers\LogoutController;
use Modules\Foundation\Authentication\Http\Controllers\OwnerMfaController;
use Modules\Foundation\Authentication\Http\Controllers\PasswordResetController;

Route::prefix('auth')->middleware(['throttle:auth'])->group(function () {
    Route::post('/login', [LoginController::class, 'login'])->name('api.login');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);

    // permission.team must come AFTER auth:sanctum so the Sanctum-resolved user
    // is available before SetPermissionTeamIdMiddleware reads $request->user().
    Route::middleware(['auth:sanctum', 'auth.security', 'permission.team'])->group(function () {
        Route::post('/logout', [LogoutController::class, 'logout'])->name('api.logout');
        Route::post('/logout/all', [LogoutController::class, 'logoutAll']);
    });
});

Route::middleware(['auth:sanctum', 'auth.security'])->group(function () {
    Route::post('/owner/mfa/recovery-codes/regenerate', [OwnerMfaController::class, 'regenerateRecoveryCodes']);
    Route::post('/owner/mfa/reset', [OwnerMfaController::class, 'reset']);
});
