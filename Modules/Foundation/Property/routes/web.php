<?php

use Illuminate\Support\Facades\Route;
use Modules\Foundation\Property\Http\Controllers\CompanyController;
use Modules\Foundation\Property\Http\Controllers\PropertyController;

Route::middleware('web')->group(function () {
    Route::middleware(['auth', 'verified'])->group(function () {
        Route::resource('companies', CompanyController::class);
        Route::resource('properties', PropertyController::class);
    });
});
