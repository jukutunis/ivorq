<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Payables\Http\Controllers\VendorInvoiceController;
use Modules\Finance\Payables\Http\Controllers\AccountPayableController;
use Modules\Finance\Payables\Http\Controllers\ThreeWayMatchController;

Route::prefix('payables')->group(function () {
    Route::post('/vendor-invoices/{id}/cancel', [VendorInvoiceController::class, 'cancel']);
    Route::apiResource('vendor-invoices', VendorInvoiceController::class)->except(['destroy']);
    
    // Three-Way Matching
    Route::post('vendor-invoices/{vendorInvoice}/match', [ThreeWayMatchController::class, 'match']);
    Route::post('vendor-invoices/{vendorInvoice}/generate-ap', [AccountPayableController::class, 'generate']);

    Route::apiResource('matches', ThreeWayMatchController::class)->only(['show']);
    Route::apiResource('accounts-payable', AccountPayableController::class)->only(['index', 'show']);
});
