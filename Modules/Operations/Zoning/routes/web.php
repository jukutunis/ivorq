<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\Zoning\Http\Controllers\ZoneAssignmentController;
use Modules\Operations\Zoning\Http\Controllers\ZoneController;
use Modules\Operations\Zoning\Http\Controllers\ZoneTemplateController;

Route::middleware(['web', 'auth'])
    ->prefix('operations')
    ->name('operations.')
    ->group(function () {

        // ── Zone resource ────────────────────────────────────────────────
        Route::resource('zones', ZoneController::class);

        Route::post('zones/{zone}/status', [ZoneController::class, 'changeStatus'])
            ->name('zones.status');

        // ── Zone assignments (nested under zone) ─────────────────────────
        Route::prefix('zones/{zone}/assignments')
            ->name('zones.assignments.')
            ->group(function () {
                Route::get('/',                               [ZoneAssignmentController::class, 'index'])->name('index');
                Route::post('/',                             [ZoneAssignmentController::class, 'store'])->name('store');
                Route::get('/{assignment}',                  [ZoneAssignmentController::class, 'show'])->name('show');
                Route::put('/{assignment}',                  [ZoneAssignmentController::class, 'update'])->name('update');
                Route::patch('/{assignment}',                [ZoneAssignmentController::class, 'update'])->name('update.patch');
                Route::delete('/{assignment}',               [ZoneAssignmentController::class, 'destroy'])->name('destroy');
                Route::post('/{assignment}/end',             [ZoneAssignmentController::class, 'end'])->name('end');
                Route::post('/{assignment}/reassign',        [ZoneAssignmentController::class, 'reassign'])->name('reassign');
            });

        // ── Zone templates ───────────────────────────────────────────────
        Route::resource('zone-templates', ZoneTemplateController::class)
            ->names('zone-templates')
            ->parameters(['zone-templates' => 'template']);
    });
