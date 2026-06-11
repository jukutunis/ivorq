<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\GeneralLedger\Http\Controllers\TrialBalanceController;

Route::prefix('general-ledger')->group(function () {
    Route::get('trial-balance', [TrialBalanceController::class, 'index'])
        // Add auth/permission middleware as required by IVORQ structure, simple one for now
        // Assuming 'auth:sanctum' and 'permission:generalledger.trialbalance.view' or similar
        // Based on the instruction: "Permission: generalledger.trialbalance.view"
        ->middleware(['can:generalledger.trialbalance.view']);
});
