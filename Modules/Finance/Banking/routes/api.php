<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Banking\Http\Controllers\BankAccountController;
use Modules\Finance\Banking\Http\Controllers\BankStatementController;

Route::prefix('banking')->group(function () {
    Route::apiResource('bank-accounts', BankAccountController::class);
    
    Route::apiResource('statements', BankStatementController::class)->only(['index', 'store', 'show']);
    Route::post('statements/{statement}/import', [BankStatementController::class, 'import']);
});
