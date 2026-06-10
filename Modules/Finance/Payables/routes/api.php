<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Payables\Http\Controllers\VendorInvoiceController;
use Modules\Finance\Payables\Http\Controllers\ThreeWayMatchController;

Route::prefix('payables')->group(function () {
    Route::post('/vendor-invoices/{id}/cancel', [VendorInvoiceController::class, 'cancel']);
    Route::apiResource('vendor-invoices', VendorInvoiceController::class)->except(['destroy']);
    
    // Three-Way Matching
    Route::post('/vendor-invoices/{id}/match', [ThreeWayMatchController::class, 'match']);
    Route::get('/matches/{id}', [ThreeWayMatchController::class, 'show']);
});
