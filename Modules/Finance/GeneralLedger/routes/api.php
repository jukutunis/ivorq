<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\GeneralLedger\Http\Controllers\TrialBalanceController;

Route::prefix('general-ledger')->group(function () {
    Route::get('trial-balance', [TrialBalanceController::class, 'index'])
        ->middleware(['can:generalledger.trialbalance.view']);

    Route::get('profit-loss', [\Modules\Finance\GeneralLedger\Http\Controllers\ProfitLossController::class, 'index'])
        ->middleware(['can:generalledger.profitloss.view']);

    Route::get('balance-sheet', [\Modules\Finance\GeneralLedger\Http\Controllers\BalanceSheetController::class, 'index'])
        ->middleware(['can:generalledger.balancesheet.view']);
});
