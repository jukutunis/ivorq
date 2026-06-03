<?php

use Illuminate\Support\Facades\Route;
use Modules\Foundation\Authorization\Http\Controllers\PermissionController;
use Modules\Foundation\Authorization\Http\Controllers\RoleController;

Route::middleware('web')->group(function () {
    Route::middleware(['auth', 'verified'])->group(function () {
        Route::resource('roles', RoleController::class)->except(['show']);
        Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
    });
});
