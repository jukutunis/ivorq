<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Payables\Http\Controllers\VendorInvoiceController;

Route::prefix('payables')->group(function () {
    Route::post('/vendor-invoices/{id}/cancel', [VendorInvoiceController::class, 'cancel']);
    Route::apiResource('vendor-invoices', VendorInvoiceController::class)->except(['destroy']);
});
