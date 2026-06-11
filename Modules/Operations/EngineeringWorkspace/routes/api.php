<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\EngineeringWorkspace\Controllers\EngineeringWorkspaceController;

Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1/operations/workspace/engineering')->group(function () {
    Route::get('/dashboard', [EngineeringWorkspaceController::class, 'dashboard']);
    Route::get('/my-tasks', [EngineeringWorkspaceController::class, 'myTasks']);
    Route::get('/my-areas', [EngineeringWorkspaceController::class, 'myAreas']);
    Route::get('/guest-impact', [EngineeringWorkspaceController::class, 'guestImpact']);
    Route::get('/asset-health', [EngineeringWorkspaceController::class, 'assetHealth']);
    Route::get('/handover', [EngineeringWorkspaceController::class, 'handover']);
    Route::get('/approvals', [EngineeringWorkspaceController::class, 'approvals']);
});
