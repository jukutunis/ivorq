<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', fn() => redirect()->route('dashboard'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/notifications', [\Modules\Foundation\Notification\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
});

use App\Http\Controllers\Ivorq\WorkspaceController;
use App\Http\Controllers\Ivorq\FrontDeskController;
use App\Http\Controllers\Ivorq\HousekeepingController;
use App\Http\Controllers\Ivorq\EngineeringController;
use App\Http\Controllers\Ivorq\FinanceController;
use App\Http\Controllers\Ivorq\HRISController;

Route::get('/frontdesk', fn() => redirect('/frontdesk/arrivals'));
Route::prefix('frontdesk')->group(function () {
    Route::get('/arrivals', [FrontDeskController::class, 'arrivals']);
    Route::get('/departures', [FrontDeskController::class, 'departures']);
    Route::get('/in-house', [FrontDeskController::class, 'inHouse']);
    Route::get('/room-readiness', [FrontDeskController::class, 'roomReadiness']);
    Route::get('/reservation-board', [FrontDeskController::class, 'reservationBoard']);
});

Route::get('/housekeeping', fn() => redirect('/housekeeping/room-board'));
Route::prefix('housekeeping')->group(function () {
    Route::get('/room-board', [HousekeepingController::class, 'roomBoard']);
    Route::get('/attendant-status', [HousekeepingController::class, 'attendantStatus']);
    Route::get('/inspections', [HousekeepingController::class, 'inspections']);
    Route::get('/lost-found', [HousekeepingController::class, 'lostFound']);
});

Route::get('/engineering', fn() => redirect('/engineering/work-orders'));
Route::prefix('engineering')->group(function () {
    Route::get('/work-orders', [EngineeringController::class, 'workOrders']);
    Route::get('/preventive-maintenance', [EngineeringController::class, 'preventiveMaintenance']);
    Route::get('/asset-registry', [EngineeringController::class, 'assetRegistry']);
    Route::get('/technician-schedule', [EngineeringController::class, 'technicianSchedule']);
});

Route::get('/finance', fn() => redirect('/finance/revenue-cash'));
Route::prefix('finance')->group(function () {
    Route::get('/revenue-cash', [FinanceController::class, 'revenueCash']);
    Route::get('/accounts-payable', [FinanceController::class, 'accountsPayable']);
    Route::get('/accounts-receivable', [FinanceController::class, 'accountsReceivable']);
    Route::get('/budget-watch', [FinanceController::class, 'budgetWatch']);
});

Route::get('/hris', fn() => redirect('/hris/attendance'));
Route::prefix('hris')->group(function () {
    Route::get('/attendance', [HRISController::class, 'attendance']);
    Route::get('/shift-coverage', [HRISController::class, 'shiftCoverage']);
    Route::get('/leave-requests', [HRISController::class, 'leaveRequests']);
    Route::get('/payroll', [HRISController::class, 'payroll']);
});
