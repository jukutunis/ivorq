<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\AssetManagement\Http\Controllers\AssetController;

Route::middleware(['auth:sanctum'])->prefix('api/v1/assets')->group(function () {
    Route::get('/', [AssetController::class, 'index']);
    Route::post('/', [AssetController::class, 'store']);
    Route::get('/{id}', [AssetController::class, 'show']);
    Route::put('/{id}', [AssetController::class, 'update']);
    Route::delete('/{id}', [AssetController::class, 'destroy']);
});
