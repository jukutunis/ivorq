<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\Maintenance\Http\Controllers\MaintenancePlanController;
use Modules\Operations\Maintenance\Http\Controllers\MaintenanceExecutionController;
use Modules\Operations\Maintenance\Http\Controllers\MaintenanceMeterReadingController;
use Modules\Operations\Maintenance\Http\Controllers\MaintenanceExceptionController;

Route::middleware(['auth:sanctum'])->prefix('api/v1/maintenance')->group(function () {
    Route::get('/plans', [MaintenancePlanController::class, 'index']);
    Route::post('/plans', [MaintenancePlanController::class, 'store']);
    Route::get('/plans/{id}', [MaintenancePlanController::class, 'show']);

    Route::get('/executions', [MaintenanceExecutionController::class, 'index']);
    Route::post('/executions', [MaintenanceExecutionController::class, 'store']);
    Route::post('/executions/{id}/complete', [MaintenanceExecutionController::class, 'complete']);

    Route::post('/meter-readings', [MaintenanceMeterReadingController::class, 'store']);
    Route::post('/exceptions', [MaintenanceExceptionController::class, 'store']);
});
