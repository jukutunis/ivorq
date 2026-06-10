<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\Purchasing\Http\Controllers\VendorCategoryController;
use Modules\Operations\Purchasing\Http\Controllers\VendorController;

Route::middleware(['auth:sanctum', \Illuminate\Routing\Middleware\SubstituteBindings::class])->prefix('purchasing')->name('purchasing.')->group(function () {
    
    // Vendor Categories
    Route::apiResource('vendor-categories', VendorCategoryController::class);
    
    // Vendors
    Route::post('vendors/{vendor}/approve', [VendorController::class, 'approve'])->name('vendors.approve');
    Route::apiResource('vendors', VendorController::class);

    // Purchase Requests
    Route::post('purchase-requests/{purchase_request}/cancel', [Modules\Operations\Purchasing\Http\Controllers\PurchaseRequestController::class, 'cancel'])->name('purchase-requests.cancel');
    Route::post('purchase-requests/{purchase_request}/submit', [Modules\Operations\Purchasing\Http\Controllers\PurchaseRequestController::class, 'submit'])->name('purchase-requests.submit');
    Route::post('purchase-requests/{purchase_request}/approve', [Modules\Operations\Purchasing\Http\Controllers\PurchaseRequestController::class, 'approve'])->name('purchase-requests.approve');
    Route::post('purchase-requests/{purchase_request}/reject', [Modules\Operations\Purchasing\Http\Controllers\PurchaseRequestController::class, 'reject'])->name('purchase-requests.reject');
    Route::apiResource('purchase-requests', Modules\Operations\Purchasing\Http\Controllers\PurchaseRequestController::class);

});
