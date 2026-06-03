<?php

use Illuminate\Support\Facades\Route;
use Modules\Foundation\User\Http\Controllers\ProfileController;
use Modules\Foundation\User\Http\Controllers\UserController;

Route::middleware('web')->group(function () {
    Route::middleware(['auth', 'verified'])->group(function () {
        Route::resource('users', UserController::class);

        Route::prefix('profile')->name('profile.')->group(function () {
            Route::get('/', [ProfileController::class, 'show'])->name('show');
            Route::put('/', [ProfileController::class, 'update'])->name('update');
            Route::put('password', [ProfileController::class, 'changePassword'])->name('password');
        });
    });
});
