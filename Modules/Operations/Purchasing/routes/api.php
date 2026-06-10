<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\Purchasing\Http\Controllers\VendorCategoryController;
use Modules\Operations\Purchasing\Http\Controllers\VendorController;
use Modules\Operations\Purchasing\Http\Controllers\PurchaseRequestController;
use Modules\Operations\Purchasing\Http\Controllers\PurchaseOrderController;
use Modules\Operations\Purchasing\Http\Controllers\GoodsReceiptController;

Route::middleware(['auth:sanctum', \Illuminate\Routing\Middleware\SubstituteBindings::class])->prefix('purchasing')->name('purchasing.')->group(function () {
    
    // Vendor Categories
    Route::apiResource('vendor-categories', VendorCategoryController::class);
    
    // Vendors
    Route::post('vendors/{vendor}/approve', [VendorController::class, 'approve'])->name('vendors.approve');
    Route::apiResource('vendors', VendorController::class);

    // Purchase Requests
    Route::post('purchase-requests/{purchase_request}/cancel', [PurchaseRequestController::class, 'cancel'])->name('purchase-requests.cancel');
    Route::post('purchase-requests/{purchase_request}/submit', [PurchaseRequestController::class, 'submit'])->name('purchase-requests.submit');
    Route::post('purchase-requests/{purchase_request}/approve', [PurchaseRequestController::class, 'approve'])->name('purchase-requests.approve');
    Route::post('purchase-requests/{purchase_request}/reject', [PurchaseRequestController::class, 'reject'])->name('purchase-requests.reject');
    Route::apiResource('purchase-requests', PurchaseRequestController::class);

    // Purchase Orders
    Route::apiResource('purchase-orders', PurchaseOrderController::class)->except(['destroy']);
    Route::post('purchase-orders/{purchase_order}/issue', [PurchaseOrderController::class, 'issue'])->name('purchase-orders.issue');
    Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');

    // Goods Receipts (Receiving)
    Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])->name('goods-receipts.store');

});
