<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\WorkOrder\Http\Controllers\WorkOrderController;
use Modules\Operations\WorkOrder\Http\Controllers\WorkOrderAssignmentController;
use Modules\Operations\WorkOrder\Http\Controllers\WorkOrderApprovalController;
use Modules\Operations\WorkOrder\Http\Controllers\WorkOrderLaborController;
use Modules\Operations\WorkOrder\Http\Controllers\WorkOrderClosureController;

Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1/operations/work-orders')->group(function () {
    Route::get('/', [WorkOrderController::class, 'index']);
    Route::post('/', [WorkOrderController::class, 'store']);
    Route::get('/{workOrder}', [WorkOrderController::class, 'show']);
    Route::patch('/{workOrder}/status', [WorkOrderController::class, 'updateStatus']);

    Route::post('/{workOrder}/assignments', [WorkOrderAssignmentController::class, 'store']);
    Route::post('/{workOrder}/approvals', [WorkOrderApprovalController::class, 'store']);
    Route::patch('/approvals/{approval}', [WorkOrderApprovalController::class, 'update']);
    Route::post('/{workOrder}/labors', [WorkOrderLaborController::class, 'store']);
    Route::post('/{workOrder}/closures', [WorkOrderClosureController::class, 'store']);
});
