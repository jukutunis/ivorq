<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\Purchasing\Http\Controllers\ProcurementWorkspaceController;

Route::middleware(['web', 'auth'])
    ->prefix('operations/procurement')
    ->name('operations.procurement.')
    ->group(function (): void {
        Route::get('/', [ProcurementWorkspaceController::class, 'index'])->name('workspace');
    });
