<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Banking\Http\Controllers\BankAccountController;

Route::prefix('banking')->group(function () {
    Route::apiResource('bank-accounts', BankAccountController::class);
});
