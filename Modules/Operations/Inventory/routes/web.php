<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\Inventory\Http\Controllers\InventoryAdjustmentController;
use Modules\Operations\Inventory\Http\Controllers\InventoryCategoryController;
use Modules\Operations\Inventory\Http\Controllers\InventoryDashboardController;
use Modules\Operations\Inventory\Http\Controllers\InventoryIssueController;
use Modules\Operations\Inventory\Http\Controllers\InventoryItemController;
use Modules\Operations\Inventory\Http\Controllers\InventoryLocationController;
use Modules\Operations\Inventory\Http\Controllers\InventoryReceiptController;
use Modules\Operations\Inventory\Http\Controllers\InventoryStockCardController;
use Modules\Operations\Inventory\Http\Controllers\InventoryTransferController;
use Modules\Operations\Inventory\Http\Controllers\InventoryUnitController;

Route::middleware(['web', 'auth'])
    ->prefix('operations/inventory')
    ->name('operations.inventory.')
    ->group(function () {

        // ── Dashboard ─────────────────────────────────────────────────────────
        Route::get('/', [InventoryDashboardController::class, 'index'])
            ->name('dashboard');

        // ── Categories ────────────────────────────────────────────────────────
        Route::resource('categories', InventoryCategoryController::class)
            ->parameters(['categories' => 'category']);

        // ── Units ─────────────────────────────────────────────────────────────
        Route::resource('units', InventoryUnitController::class)
            ->parameters(['units' => 'unit']);

        // ── Locations ─────────────────────────────────────────────────────────
        Route::resource('locations', InventoryLocationController::class)
            ->parameters(['locations' => 'location']);

        // ── Items ─────────────────────────────────────────────────────────────
        Route::resource('items', InventoryItemController::class)
            ->parameters(['items' => 'item']);

        // ── Receipts ──────────────────────────────────────────────────────────
        Route::resource('receipts', InventoryReceiptController::class)
            ->parameters(['receipts' => 'receipt']);

        Route::post('receipts/{receipt}/post', [InventoryReceiptController::class, 'post'])
            ->name('receipts.post');

        Route::post('receipts/{receipt}/cancel', [InventoryReceiptController::class, 'cancel'])
            ->name('receipts.cancel');

        // ── Issues ────────────────────────────────────────────────────────────
        Route::resource('issues', InventoryIssueController::class)
            ->parameters(['issues' => 'issue']);

        Route::post('issues/{issue}/post', [InventoryIssueController::class, 'post'])
            ->name('issues.post');

        Route::post('issues/{issue}/cancel', [InventoryIssueController::class, 'cancel'])
            ->name('issues.cancel');

        // ── Transfers ─────────────────────────────────────────────────────────
        Route::resource('transfers', InventoryTransferController::class)
            ->parameters(['transfers' => 'transfer']);

        Route::post('transfers/{transfer}/complete', [InventoryTransferController::class, 'complete'])
            ->name('transfers.complete');

        Route::post('transfers/{transfer}/cancel', [InventoryTransferController::class, 'cancel'])
            ->name('transfers.cancel');

        // ── Adjustments ───────────────────────────────────────────────────────
        Route::resource('adjustments', InventoryAdjustmentController::class)
            ->parameters(['adjustments' => 'adjustment']);

        Route::post('adjustments/{adjustment}/submit', [InventoryAdjustmentController::class, 'submit'])
            ->name('adjustments.submit');

        Route::post('adjustments/{adjustment}/approve', [InventoryAdjustmentController::class, 'approve'])
            ->name('adjustments.approve');

        Route::post('adjustments/{adjustment}/reject', [InventoryAdjustmentController::class, 'reject'])
            ->name('adjustments.reject');

        Route::post('adjustments/{adjustment}/cancel', [InventoryAdjustmentController::class, 'cancel'])
            ->name('adjustments.cancel');

        // ── Stock Cards (read-only) ───────────────────────────────────────────
        Route::get('stock-cards',        [InventoryStockCardController::class, 'index'])->name('stock-cards.index');
        Route::get('stock-cards/{card}', [InventoryStockCardController::class, 'show'])->name('stock-cards.show');
    });
