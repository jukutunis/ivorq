<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Payables\Http\Controllers\AccountPayableController;
use Modules\Finance\Payables\Http\Controllers\PaymentVoucherController;
use Modules\Finance\Payables\Http\Controllers\ThreeWayMatchController;

Route::prefix('payables')->group(function () {
    Route::apiResource('matches', ThreeWayMatchController::class)->only(['show']);
    Route::apiResource('accounts-payable', AccountPayableController::class)->only(['index', 'show']);

    Route::post('payment-vouchers/{paymentVoucher}/post', [PaymentVoucherController::class, 'post']);
    Route::post('payment-vouchers/{paymentVoucher}/cancel', [PaymentVoucherController::class, 'cancel']);
    Route::apiResource('payment-vouchers', PaymentVoucherController::class)->only(['index', 'show', 'store']);
});
