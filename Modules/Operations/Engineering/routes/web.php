<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\Engineering\Http\Controllers\AssetRequestController;
use Modules\Operations\Engineering\Http\Controllers\EngineeringChecklistController;
use Modules\Operations\Engineering\Http\Controllers\PreventiveMaintenanceController;
use Modules\Operations\Engineering\Http\Controllers\PreventiveMaintenanceTaskController;
use Modules\Operations\Engineering\Http\Controllers\TechnicianAssignmentController;
use Modules\Operations\Engineering\Http\Controllers\WorkOrderController;

Route::middleware(['web', 'auth'])
    ->prefix('operations')
    ->name('operations.')
    ->group(function () {

        // ── Work Orders ──────────────────────────────────────────────────────
        Route::resource('work-orders', WorkOrderController::class)
            ->parameters(['work-orders' => 'wo']);

        Route::post('work-orders/{wo}/status', [WorkOrderController::class, 'changeStatus'])
            ->name('work-orders.status');

        Route::post('work-orders/{wo}/assign', [WorkOrderController::class, 'assign'])
            ->name('work-orders.assign');

        Route::post('work-orders/{wo}/approve', [WorkOrderController::class, 'approve'])
            ->name('work-orders.approve');

        // ── Technician Assignments (nested under work-orders) ────────────────
        Route::prefix('work-orders/{wo}/assignments')
            ->name('work-orders.assignments.')
            ->group(function () {
                Route::post('/',                                   [TechnicianAssignmentController::class, 'store'])->name('store');
                Route::put('/{assignment}',                        [TechnicianAssignmentController::class, 'update'])->name('update');
                Route::patch('/{assignment}',                      [TechnicianAssignmentController::class, 'update'])->name('update.patch');
                Route::delete('/{assignment}',                     [TechnicianAssignmentController::class, 'destroy'])->name('destroy');
                Route::post('/{assignment}/complete',              [TechnicianAssignmentController::class, 'complete'])->name('complete');
            });

        // ── Preventive Maintenance ───────────────────────────────────────────
        Route::resource('preventive-maintenances', PreventiveMaintenanceController::class)
            ->parameters(['preventive-maintenances' => 'pm']);

        Route::post('preventive-maintenances/{pm}/generate-task', [PreventiveMaintenanceController::class, 'generateTask'])
            ->name('preventive-maintenances.generate-task');

        // ── PM Tasks (nested under preventive-maintenances) ──────────────────
        Route::prefix('preventive-maintenances/{pm}/tasks')
            ->name('preventive-maintenances.tasks.')
            ->group(function () {
                Route::get('/{task}',              [PreventiveMaintenanceTaskController::class, 'show'])->name('show');
                Route::post('/{task}/status',      [PreventiveMaintenanceTaskController::class, 'changeStatus'])->name('status');
                Route::post('/{task}/work-order',  [PreventiveMaintenanceTaskController::class, 'createWorkOrder'])->name('work-order');
            });

        // ── Asset Requests ───────────────────────────────────────────────────
        Route::resource('asset-requests', AssetRequestController::class)
            ->parameters(['asset-requests' => 'req']);

        Route::post('asset-requests/{req}/approve', [AssetRequestController::class, 'approve'])
            ->name('asset-requests.approve');

        Route::post('asset-requests/{req}/reject', [AssetRequestController::class, 'reject'])
            ->name('asset-requests.reject');

        Route::post('asset-requests/{req}/fulfill', [AssetRequestController::class, 'fulfill'])
            ->name('asset-requests.fulfill');

        // ── Engineering Checklists ───────────────────────────────────────────
        Route::resource('engineering-checklists', EngineeringChecklistController::class)
            ->parameters(['engineering-checklists' => 'checklist']);

        Route::prefix('engineering-checklists/{checklist}/items')
            ->name('engineering-checklists.items.')
            ->group(function () {
                Route::post('/',             [EngineeringChecklistController::class, 'addItem'])->name('store');
                Route::put('/{item}',        [EngineeringChecklistController::class, 'updateItem'])->name('update');
                Route::patch('/{item}',      [EngineeringChecklistController::class, 'updateItem'])->name('update.patch');
                Route::delete('/{item}',     [EngineeringChecklistController::class, 'deleteItem'])->name('destroy');
                Route::post('/reorder',      [EngineeringChecklistController::class, 'reorderItems'])->name('reorder');
            });
    });
