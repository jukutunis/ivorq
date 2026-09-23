<?php

use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Modules\Foundation\Authentication\Http\Controllers\LoginController;
use Modules\Foundation\Authentication\Http\Controllers\LogoutController;
use Modules\Foundation\Authentication\Http\Controllers\OwnerActivationController;
use Modules\Foundation\Authentication\Http\Controllers\OwnerMfaController;
use Modules\Foundation\Authentication\Http\Controllers\PasswordResetController;
use Modules\Foundation\Authentication\Http\Middleware\EnsureAuthenticatedSecurity;
use Modules\Foundation\Authorization\Http\Middleware\SetPermissionTeamIdMiddleware;

Route::middleware('web')
    ->withoutMiddleware([
        AuthenticateSession::class,
        EnsureAuthenticatedSecurity::class,
        SetPermissionTeamIdMiddleware::class,
    ])
    ->group(function () {
        Route::post('/auth/mfa/totp', [OwnerMfaController::class, 'totp']);
        Route::post('/auth/mfa/recovery-code', [OwnerMfaController::class, 'recoveryCode']);
    });

// All authentication web routes need the 'web' middleware group so that
// StartSession, ShareErrorsFromSession, and VerifyCsrfToken run. Module routes
// loaded via loadRoutesFrom() are not automatically wrapped in 'web'.
Route::middleware(['web', 'throttle:auth'])->group(function () {
    Route::post('/owner-activation/email/verify', [OwnerActivationController::class, 'verifyEmail']);
    Route::post('/owner-activation/resume/request', [OwnerActivationController::class, 'requestResume']);
    Route::post('/owner-activation/resume/redeem', [OwnerActivationController::class, 'redeemResume']);
    Route::post('/owner-activation/password', [OwnerActivationController::class, 'password']);
    Route::post('/owner-activation/mfa/enroll', [OwnerActivationController::class, 'enrollMfa']);
    Route::post('/owner-activation/mfa/confirm', [OwnerActivationController::class, 'confirmMfa']);
    Route::post('/owner-activation/complete', [OwnerActivationController::class, 'complete']);
    Route::middleware('guest')->group(function () {
        Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
        Route::post('/login/tenant', [LoginController::class, 'resolveTenant'])->middleware('throttle:cloud_name');
        Route::delete('/login/tenant', [LoginController::class, 'clearTenant']);
        Route::post('/login', [LoginController::class, 'login']);

        Route::get('/forgot-password', [PasswordResetController::class, 'showForgotForm'])->name('password.request');
        Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');

        Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
    });

    Route::middleware(['auth', 'auth.security'])->group(function () {
        Route::post('/login/property', [LoginController::class, 'selectProperty']);
        Route::post('/logout', [LogoutController::class, 'logout'])->name('logout');
        Route::post('/logout/all', [LogoutController::class, 'logoutAll'])->name('logout.all');
    });
});
