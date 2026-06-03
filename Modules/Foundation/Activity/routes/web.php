<?php

use Illuminate\Support\Facades\Route;
use Modules\Foundation\Activity\Http\Controllers\ActivityLogController;

Route::middleware('web')->group(function () {
    Route::middleware(['auth', 'verified'])->prefix('activity')->name('activity.')->group(function () {
        Route::get('/', [ActivityLogController::class, 'index'])->name('index');
        Route::get('/{id}', [ActivityLogController::class, 'show'])->name('show');
    });
});
