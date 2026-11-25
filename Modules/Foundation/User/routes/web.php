<?php

use Illuminate\Support\Facades\Route;
use Modules\Foundation\User\Http\Controllers\ProfileController;
use Modules\Foundation\User\Http\Controllers\ProfileSessionController;
use Modules\Foundation\User\Http\Controllers\UserController;

Route::middleware('web')->group(function () {
    Route::middleware(['auth', 'verified', 'auth.security', 'active.property'])->group(function () {
        Route::resource('users', UserController::class)->except(['destroy']);
        Route::post('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('users/{user}/offboard', [UserController::class, 'offboard'])->name('users.offboard');
        Route::post('users/{user}/default-property', [UserController::class, 'setDefaultProperty'])->name('users.default-property');
        Route::delete('users/{user}/memberships/current', [UserController::class, 'removeCurrentMembership'])->name('users.memberships.current.destroy');
    });

    Route::middleware(['auth', 'verified', 'auth.security'])->group(function () {
        Route::prefix('profile')->name('profile.')->group(function () {
            Route::get('/', [ProfileController::class, 'show'])->name('show');
            Route::put('/', [ProfileController::class, 'update'])->name('update');
            Route::put('password', [ProfileController::class, 'changePassword'])->name('password');
            Route::delete('sessions/{session}', [ProfileSessionController::class, 'destroy'])->name('sessions.destroy');
        });
    });
});
