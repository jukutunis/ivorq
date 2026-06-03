<?php

use Illuminate\Support\Facades\Route;
use Modules\Foundation\Audit\Http\Controllers\AuditLogController;

Route::middleware('web')->group(function () {
    Route::middleware(['auth', 'verified'])->prefix('audit')->name('audit.')->group(function () {
        Route::get('/', [AuditLogController::class, 'index'])->name('index');
        Route::get('/{id}', [AuditLogController::class, 'show'])->name('show');
    });
});
