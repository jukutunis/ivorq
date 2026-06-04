<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\Housekeeping\Http\Controllers\CleaningChecklistController;
use Modules\Operations\Housekeeping\Http\Controllers\CleaningTaskController;
use Modules\Operations\Housekeeping\Http\Controllers\HousekeepingDashboardController;
use Modules\Operations\Housekeeping\Http\Controllers\RoomController;
use Modules\Operations\Housekeeping\Http\Controllers\RoomInspectionController;
use Modules\Operations\Housekeeping\Http\Controllers\TaskAssignmentController;

Route::middleware(['web', 'auth'])
    ->prefix('operations')
    ->name('operations.')
    ->group(function () {

        // ── Dashboard ────────────────────────────────────────────────────
        Route::get('housekeeping', [HousekeepingDashboardController::class, 'index'])
            ->name('housekeeping.dashboard');

        // ── Rooms ─────────────────────────────────────────────────────────
        Route::resource('rooms', RoomController::class);

        Route::post('rooms/{room}/cleanliness', [RoomController::class, 'changeCleanliness'])
            ->name('rooms.cleanliness');

        Route::post('rooms/{room}/occupancy', [RoomController::class, 'changeOccupancy'])
            ->name('rooms.occupancy');

        // ── Cleaning Tasks ────────────────────────────────────────────────
        Route::resource('cleaning-tasks', CleaningTaskController::class)
            ->parameters(['cleaning-tasks' => 'task']);

        Route::post('cleaning-tasks/{task}/status', [CleaningTaskController::class, 'changeStatus'])
            ->name('cleaning-tasks.status');

        Route::post('cleaning-tasks/{task}/assign', [CleaningTaskController::class, 'assign'])
            ->name('cleaning-tasks.assign');

        // ── Task Assignments (nested under task) ──────────────────────────
        Route::prefix('cleaning-tasks/{task}/assignments')
            ->name('cleaning-tasks.assignments.')
            ->group(function () {
                Route::post('/{assignment}/complete', [TaskAssignmentController::class, 'complete'])
                    ->name('complete');
                Route::post('/{assignment}/cancel', [TaskAssignmentController::class, 'cancel'])
                    ->name('cancel');
            });

        // ── Room Inspections ──────────────────────────────────────────────
        Route::resource('inspections', RoomInspectionController::class)
            ->parameters(['inspections' => 'inspection']);

        Route::post('inspections/{inspection}/conduct', [RoomInspectionController::class, 'conduct'])
            ->name('inspections.conduct');

        Route::post('inspections/{inspection}/pass', [RoomInspectionController::class, 'pass'])
            ->name('inspections.pass');

        Route::post('inspections/{inspection}/fail', [RoomInspectionController::class, 'fail'])
            ->name('inspections.fail');

        // ── Cleaning Checklists ───────────────────────────────────────────
        Route::resource('checklists', CleaningChecklistController::class)
            ->parameters(['checklists' => 'checklist']);

        Route::prefix('checklists/{checklist}/items')
            ->name('checklists.items.')
            ->group(function () {
                Route::post('/',             [CleaningChecklistController::class, 'addItem'])
                    ->name('store');
                Route::put('/{item}',        [CleaningChecklistController::class, 'updateItem'])
                    ->name('update');
                Route::patch('/{item}',      [CleaningChecklistController::class, 'updateItem'])
                    ->name('update.patch');
                Route::delete('/{item}',     [CleaningChecklistController::class, 'deleteItem'])
                    ->name('destroy');
                Route::post('/reorder',      [CleaningChecklistController::class, 'reorderItems'])
                    ->name('reorder');
            });
    });
