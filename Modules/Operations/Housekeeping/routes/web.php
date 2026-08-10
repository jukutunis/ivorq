<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\Housekeeping\Http\Controllers\CleaningChecklistController;
use Modules\Operations\Housekeeping\Http\Controllers\CleaningTaskController;
use Modules\Operations\Housekeeping\Http\Controllers\HousekeepingDashboardController;
use Modules\Operations\Housekeeping\Http\Controllers\HousekeepingCheckoutTurnoverWorkspaceController;
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

        Route::get('housekeeping/checkout-turnovers', [HousekeepingCheckoutTurnoverWorkspaceController::class, 'index'])
            ->name('housekeeping.checkout-turnovers.index');

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

        Route::post('cleaning-tasks/{task}/assign', [TaskAssignmentController::class, 'assign'])
            ->name('cleaning-tasks.assign');

        // ── Room Inspections ──────────────────────────────────────────────
        Route::resource('inspections', RoomInspectionController::class)
            ->parameters(['inspections' => 'inspection']);

        Route::post('inspections/{inspection}/conduct', [RoomInspectionController::class, 'conduct'])
            ->name('inspections.conduct');

        Route::post('inspections/{inspection}/pass', [RoomInspectionController::class, 'pass'])
            ->name('inspections.pass');

        Route::post('inspections/{inspection}/pass-confirmation', [RoomInspectionController::class, 'confirmPass'])
            ->name('inspections.pass-confirmation');

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

        // ── Room Readiness Transitions ─────────────────────────────────────
        Route::prefix('room-readiness')
            ->name('room-readiness.')
            ->group(function () {
                Route::post('/start-cleaning', [\Modules\Operations\Housekeeping\Http\Controllers\HousekeepingRoomReadinessController::class, 'startCleaning'])
                    ->name('start-cleaning');
                Route::post('/submit-inspection', [\Modules\Operations\Housekeeping\Http\Controllers\HousekeepingRoomReadinessController::class, 'submitInspection'])
                    ->name('submit-inspection');
                Route::post('/release-ready', [\Modules\Operations\Housekeeping\Http\Controllers\HousekeepingRoomReadinessController::class, 'releaseReady'])
                    ->name('release-ready');
                Route::get('/{room}', [\Modules\Operations\Housekeeping\Http\Controllers\HousekeepingRoomReadinessController::class, 'show'])
                    ->name('show');
            });
    });
