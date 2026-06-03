<?php

use Illuminate\Support\Facades\Route;
use Modules\Foundation\Department\Http\Controllers\DepartmentController;
use Modules\Foundation\Department\Http\Controllers\PositionController;

Route::middleware('web')->group(function () {
    Route::middleware(['auth', 'verified'])->group(function () {
        Route::resource('departments', DepartmentController::class);
        Route::resource('positions', PositionController::class)->except(['create', 'edit']);
        Route::get('departments/{department}/positions', [PositionController::class, 'index'])
            ->name('departments.positions');
    });
});
