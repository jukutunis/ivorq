<?php

use Illuminate\Support\Facades\Route;
use Modules\Foundation\Authentication\Http\Controllers\LoginController;
use Modules\Foundation\Authentication\Http\Controllers\LogoutController;
use Modules\Foundation\Authentication\Http\Controllers\PasswordResetController;

// All authentication web routes need the 'web' middleware group so that
// StartSession, ShareErrorsFromSession, and VerifyCsrfToken run. Module routes
// loaded via loadRoutesFrom() are not automatically wrapped in 'web'.
Route::middleware(['web', 'throttle:auth'])->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [LoginController::class, 'login']);

        Route::get('/forgot-password', [PasswordResetController::class, 'showForgotForm'])->name('password.request');
        Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');

        Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
    });

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [LogoutController::class, 'logout'])->name('logout');
        Route::post('/logout/all', [LogoutController::class, 'logoutAll'])->name('logout.all');
    });
});
