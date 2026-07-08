<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', fn() => redirect('/frontdesk'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/notifications', [\Modules\Foundation\Notification\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
});

use App\Http\Controllers\Ivorq\WorkspaceController;
use App\Http\Controllers\Ivorq\FrontDeskController;
use App\Http\Controllers\Ivorq\HousekeepingController;
use App\Http\Controllers\Ivorq\EngineeringController;
use App\Http\Controllers\Ivorq\FinanceController;
use App\Http\Controllers\Ivorq\HRISController;
use App\Http\Controllers\Ivorq\ShiftLogController;

Route::middleware(['auth', 'active.property'])->group(function () {
    Route::get('/frontdesk', fn() => redirect('/frontdesk/arrivals'));
    Route::prefix('frontdesk')->group(function () {
        Route::get('/arrivals', [FrontDeskController::class, 'arrivals'])->name('frontdesk.arrivals');
        Route::post('/room-assignments', [FrontDeskController::class, 'assignRoom'])
            ->name('frontdesk.room-assignments.store');
        Route::post('/stays/{stay}/check-in-confirmation', [FrontDeskController::class, 'prepareCheckInConfirmation'])
            ->name('frontdesk.stays.check-in-confirmation.store');
        Route::post('/stays/{stay}/check-in', [FrontDeskController::class, 'checkIn'])
            ->name('frontdesk.stays.check-in.store');
        Route::post('/stays/{stay}/room-move-confirmation', [FrontDeskController::class, 'prepareRoomMoveConfirmation'])
            ->name('frontdesk.stays.room-move-confirmation.store');
        Route::post('/stays/{stay}/room-move', [FrontDeskController::class, 'roomMove'])
            ->name('frontdesk.stays.room-move.store');
        Route::get('/departures', [FrontDeskController::class, 'departures']);
        Route::get('/in-house', [FrontDeskController::class, 'inHouse']);
        Route::get('/room-readiness', [FrontDeskController::class, 'roomReadiness']);
        Route::get('/stays/{stay}/checkout-readiness', [FrontDeskController::class, 'checkoutReadiness'])
            ->name('frontdesk.stays.checkout-readiness.show');
        Route::get('/reservation-board', [FrontDeskController::class, 'reservationBoard']);
        Route::get('/engineering-availability/{room}', [\Modules\Operations\FrontDesk\Http\Controllers\EngineeringAvailabilityDependencyController::class, 'show'])
            ->name('frontdesk.engineering-availability.show');
        Route::get('/housekeeping-readiness/{room}', [\Modules\Operations\FrontDesk\Http\Controllers\HousekeepingReadinessDependencyController::class, 'show'])
            ->name('frontdesk.housekeeping-readiness.show');
        Route::post('/stays/{stay}/departure-preparation-events', [FrontDeskController::class, 'createDeparturePreparationEvent'])
            ->name('frontdesk.stays.departure-preparation-events.store');
        Route::get('/stays/{stay}/departure-preparation-events', [FrontDeskController::class, 'departurePreparationEvents'])
            ->name('frontdesk.stays.departure-preparation-events.index');
        Route::post('/stays/{stay}/departure-operational-handovers', [FrontDeskController::class, 'createDepartureOperationalHandover'])
            ->name('frontdesk.stays.departure-operational-handovers.store');
        Route::get('/stays/{stay}/departure-operational-handovers', [FrontDeskController::class, 'departureOperationalHandover'])
            ->name('frontdesk.stays.departure-operational-handovers.index');
    });

    Route::get('/housekeeping', fn() => redirect('/housekeeping/room-board'));
    Route::prefix('housekeeping')->group(function () {
        Route::get('/room-board', [HousekeepingController::class, 'roomBoard']);
        Route::get('/attendant-status', [HousekeepingController::class, 'attendantStatus']);
        Route::get('/inspections', [HousekeepingController::class, 'inspections']);
        Route::get('/lost-found', [HousekeepingController::class, 'lostFound']);
        Route::get('/room-readiness', [HousekeepingController::class, 'roomReadiness']);
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
        Route::get('/payables/ap-grni-settlement-control', [\Modules\Finance\Payables\Http\Controllers\ApGrniSettlementControlWorkspaceController::class, 'index'])
            ->name('finance.payables.ap-grni-settlement-control');
        Route::post('/payables/ap-grni-settlement-control/payment-proposals', [\Modules\Finance\Payables\Http\Controllers\ApGrniSettlementControlWorkspaceController::class, 'createDraft'])
            ->name('finance.payables.ap-grni-settlement-control.payment-proposals.create');
        Route::post('/payables/ap-grni-settlement-control/payment-proposals/{paymentProposal}/cancel', [\Modules\Finance\Payables\Http\Controllers\ApGrniSettlementControlWorkspaceController::class, 'cancelDraft'])
            ->name('finance.payables.ap-grni-settlement-control.payment-proposals.cancel');
        Route::post('/payables/settlement-allocations', [\Modules\Finance\Payables\Http\Controllers\ApGrniSettlementControlWorkspaceController::class, 'allocate'])
            ->name('finance.payables.settlement-allocations.allocate');
        Route::get('/payables/payment-proposals', [\Modules\Finance\Payables\Http\Controllers\PaymentProposalControlWorkspaceController::class, 'index'])
            ->name('finance.payables.payment-proposals.index');
        Route::post('/payables/payment-proposals/{proposal}/approve', [\Modules\Finance\Payables\Http\Controllers\PaymentProposalControlWorkspaceController::class, 'approve'])
            ->name('finance.payables.payment-proposals.approve');
        Route::post('/payables/payment-proposals/{proposal}/reject', [\Modules\Finance\Payables\Http\Controllers\PaymentProposalControlWorkspaceController::class, 'reject'])
            ->name('finance.payables.payment-proposals.reject');
        Route::get('/payables/cashbook-evidence', [\Modules\Finance\GeneralCashier\Http\Controllers\CashbookEvidenceWorkspaceController::class, 'index'])
            ->name('finance.payables.cashbook-evidence.index');
        Route::post('/payables/cash-payment-execute', [\Modules\Finance\GeneralCashier\Http\Controllers\CashbookEvidenceWorkspaceController::class, 'execute'])
            ->name('finance.payables.cash-payment-execute.execute');
        Route::post('/payables/cash-reconciliation', [\Modules\Finance\GeneralCashier\Http\Controllers\CashbookEvidenceWorkspaceController::class, 'reconcile'])
            ->name('finance.payables.cash-reconciliation.reconcile');
        Route::get('/banking/operations', [\Modules\Finance\Banking\Http\Controllers\BankingOperationsWorkspaceController::class, 'index'])
            ->name('finance.banking.operations.index');
        Route::post('/banking/bank-payment-execute', [\Modules\Finance\Banking\Http\Controllers\BankingOperationsWorkspaceController::class, 'execute'])
            ->name('finance.banking.bank-payment-execute.execute');
        Route::post('/banking/bank-reconciliation', [\Modules\Finance\Banking\Http\Controllers\BankingOperationsWorkspaceController::class, 'reconcile'])
            ->name('finance.banking.bank-reconciliation.reconcile');
        Route::get('/banking/migration', [\Modules\Finance\Banking\Http\Controllers\BankingMigrationPlanController::class, 'index'])
            ->name('finance.banking.migration.index');
        Route::post('/banking/migration/plan', [\Modules\Finance\Banking\Http\Controllers\BankingMigrationPlanController::class, 'create'])
            ->name('finance.banking.migration.plan.create');
        Route::post('/banking/migration/plan/{plan}/request-dry-run', [\Modules\Finance\Banking\Http\Controllers\BankingMigrationPlanController::class, 'requestDryRun'])
            ->name('finance.banking.migration.plan.request-dry-run');
        Route::post('/banking/migration/plan/{plan}/execute-dry-run', [\Modules\Finance\Banking\Http\Controllers\BankingMigrationPlanController::class, 'executeDryRun'])
            ->name('finance.banking.migration.plan.execute-dry-run');
        Route::post('/banking/migration/target-intake/propose', [\Modules\Finance\Banking\Http\Controllers\BankingMigrationPlanController::class, 'propose'])
            ->name('finance.banking.migration.target-intake.propose');
        Route::post('/banking/migration/target-intake/{intake}/review', [\Modules\Finance\Banking\Http\Controllers\BankingMigrationPlanController::class, 'review'])
            ->name('finance.banking.migration.target-intake.review');
        Route::post('/banking/migration/pilot-authorization/request', [\Modules\Finance\Banking\Http\Controllers\BankingMigrationPlanController::class, 'requestPilotAuthorization'])
            ->name('finance.banking.migration.pilot-authorization.request');
        Route::post('/banking/migration/pilot-authorization/{pilotAuth}/review', [\Modules\Finance\Banking\Http\Controllers\BankingMigrationPlanController::class, 'reviewPilotAuthorization'])
            ->name('finance.banking.migration.pilot-authorization.review');
        Route::post('/banking/migration/pilot-authorization/{pilotAuth}/execute', [\Modules\Finance\Banking\Http\Controllers\BankingMigrationPlanController::class, 'execute'])
            ->name('finance.banking.migration.pilot-authorization.execute');
        Route::get('/payables/supplier-invoices', [\Modules\Finance\Payables\Http\Controllers\SupplierInvoiceControlWorkspaceController::class, 'index'])
            ->name('finance.payables.supplier-invoices.index');
        Route::post('/payables/supplier-invoices/{invoice}/approve', [\Modules\Finance\Payables\Http\Controllers\SupplierInvoiceControlWorkspaceController::class, 'approve'])
            ->name('finance.payables.supplier-invoices.approve');
        Route::post('/payables/supplier-invoices/{invoice}/reject', [\Modules\Finance\Payables\Http\Controllers\SupplierInvoiceControlWorkspaceController::class, 'reject'])
            ->name('finance.payables.supplier-invoices.reject');
        Route::post('/payables/supplier-invoices/{invoice}/resolve-exception', [\Modules\Finance\Payables\Http\Controllers\SupplierInvoiceControlWorkspaceController::class, 'resolveException'])
            ->name('finance.payables.supplier-invoices.resolve-exception');
        Route::get('/accounts-receivable', [FinanceController::class, 'accountsReceivable']);
        Route::get('/budget-watch', [FinanceController::class, 'budgetWatch']);
        Route::get('/general-ledger/grni-control', [\Modules\Finance\GeneralLedger\Http\Controllers\GrniControlWorkspaceController::class, 'index'])
            ->name('finance.general-ledger.grni-control');
        Route::get('/fx-adjustments', [\Modules\Finance\FxReference\Http\Controllers\FxAdjustmentControlWorkspaceController::class, 'index'])
            ->name('finance.fx-adjustments.index');
        Route::get('/fx-access-management', [\Modules\Foundation\Authorization\Http\Controllers\FxOperationalRoleAssignmentController::class, 'index'])
            ->name('finance.fx-operational-role-assignments.index');
        Route::post('/fx-access-management', [\Modules\Foundation\Authorization\Http\Controllers\FxOperationalRoleAssignmentController::class, 'store'])
            ->name('finance.fx-operational-role-assignments.store');
        Route::get('/fx-break-glass', [\Modules\Finance\FxReference\Http\Controllers\FxBreakGlassController::class, 'index'])
            ->name('finance.fx-break-glass.index');
        Route::post('/fx-break-glass', [\Modules\Finance\FxReference\Http\Controllers\FxBreakGlassController::class, 'store'])
            ->name('finance.fx-break-glass.store');
        Route::delete('/fx-break-glass', [\Modules\Finance\FxReference\Http\Controllers\FxBreakGlassController::class, 'destroy'])
            ->name('finance.fx-break-glass.destroy');
        Route::post('/fx-adjustments/candidates', [\Modules\Finance\FxReference\Http\Controllers\FxAdjustmentControlWorkspaceController::class, 'create'])
            ->name('finance.fx-adjustments.candidates.create');
        Route::post('/fx-adjustments/candidates/{candidate}/review', [\Modules\Finance\FxReference\Http\Controllers\FxAdjustmentControlWorkspaceController::class, 'review'])
            ->name('finance.fx-adjustments.candidates.review');
        Route::post('/fx-adjustments/candidates/{candidate}/materialize', [\Modules\Finance\FxReference\Http\Controllers\FxAdjustmentControlWorkspaceController::class, 'materialize'])
            ->name('finance.fx-adjustments.candidates.materialize');
        Route::post('/fx-adjustments/journals/{journalEntry}/authorize-finalization', [\Modules\Finance\FxReference\Http\Controllers\FxAdjustmentControlWorkspaceController::class, 'authorizeFinalization'])
            ->name('finance.fx-adjustments.journals.authorize-finalization');
        Route::post('/fx-adjustments/journals/{journalEntry}/post', [\Modules\Finance\FxReference\Http\Controllers\FxAdjustmentControlWorkspaceController::class, 'post'])
            ->name('finance.fx-adjustments.journals.post');
        Route::post('/general-ledger/grni-control/candidates/{candidate}/approve', [\Modules\Finance\GeneralLedger\Http\Controllers\GrniControlWorkspaceController::class, 'approve'])
            ->name('finance.general-ledger.grni-control.candidates.approve');
        Route::post('/general-ledger/grni-control/candidates/{candidate}/reject', [\Modules\Finance\GeneralLedger\Http\Controllers\GrniControlWorkspaceController::class, 'reject'])
            ->name('finance.general-ledger.grni-control.candidates.reject');
        Route::post('/general-ledger/grni-control/candidates/{candidate}/materialize', [\Modules\Finance\GeneralLedger\Http\Controllers\GrniControlWorkspaceController::class, 'materialize'])
            ->name('finance.general-ledger.grni-control.candidates.materialize');
        Route::post('/general-ledger/grni-control/journals/{journalEntry}/authorize-finalization', [\Modules\Finance\GeneralLedger\Http\Controllers\GrniControlWorkspaceController::class, 'authorizeFinalization'])
            ->name('finance.general-ledger.grni-control.journals.authorize-finalization');
        Route::post('/general-ledger/grni-control/journals/{journalEntry}/post', [\Modules\Finance\GeneralLedger\Http\Controllers\GrniControlWorkspaceController::class, 'post'])
            ->name('finance.general-ledger.grni-control.journals.post');
    });

    Route::get('/hris', fn() => redirect('/hris/attendance'));
    Route::prefix('hris')->group(function () {
        Route::get('/attendance', [HRISController::class, 'attendance']);
        Route::get('/shift-coverage', [HRISController::class, 'shiftCoverage']);
        Route::get('/leave-requests', [HRISController::class, 'leaveRequests']);
        Route::get('/payroll', [HRISController::class, 'payroll']);
    });

    Route::get('/logbook', [ShiftLogController::class, 'index']);
    Route::post('/api/v1/operations/shift-logs', [ShiftLogController::class, 'store']);
    Route::patch('/api/v1/operations/shift-logs/{shiftLog}', [ShiftLogController::class, 'update']);
    Route::post('/api/v1/operations/shift-logs/{shiftLog}/submit', [ShiftLogController::class, 'submit']);
    Route::post('/api/v1/operations/shift-logs/{shiftLog}/acknowledge', [ShiftLogController::class, 'acknowledge']);

    Route::get('/api/v1/operations/logbook-entries', [\App\Http\Controllers\Ivorq\LogbookEntryController::class, 'index']);
    Route::post('/api/v1/operations/logbook-entries', [\App\Http\Controllers\Ivorq\LogbookEntryController::class, 'store']);
    Route::patch('/api/v1/operations/logbook-entries/{logbookEntry}', [\App\Http\Controllers\Ivorq\LogbookEntryController::class, 'update']);
    Route::post('/api/v1/operations/logbook-entries/{logbookEntry}/submit', [\App\Http\Controllers\Ivorq\LogbookEntryController::class, 'submit']);
    Route::post('/api/v1/operations/logbook-entries/{logbookEntry}/follow-up-resolution', [\App\Http\Controllers\Ivorq\LogbookEntryFollowUpResolutionController::class, 'resolve']);
    Route::post('/api/v1/operations/logbook-entries/{logbookEntry}/self-corrections', [\App\Http\Controllers\Ivorq\LogbookEntrySelfCorrectionController::class, 'append']);

    // BEO Distribution — Sprint 14.8.5
    Route::post('/api/v1/sales-events/beo-distributions', [\Modules\SalesAndEventManagement\Http\Controllers\BEODistributionController::class, 'distribute']);
    Route::post('/api/v1/sales-events/beo-distributions/{distribution}/cancel', [\Modules\SalesAndEventManagement\Http\Controllers\BEODistributionController::class, 'cancel']);
    Route::post('/api/v1/sales-events/beo-acknowledgements/{acknowledgement}/acknowledge', [\Modules\SalesAndEventManagement\Http\Controllers\BEODistributionController::class, 'acknowledge']);
    Route::post('/api/v1/sales-events/beo-acknowledgements/{acknowledgement}/reject', [\Modules\SalesAndEventManagement\Http\Controllers\BEODistributionController::class, 'reject']);
    Route::get('/api/v1/sales-events/beo-distributions/{distribution}', [\Modules\SalesAndEventManagement\Http\Controllers\BEODistributionController::class, 'show']);

    Route::get('/system/sensitive-action-confirmation', [\Modules\Foundation\Authorization\Http\Controllers\SensitiveActionConfirmationController::class, 'index'])
        ->name('system.sensitive-action-confirmation.index');
    Route::post('/system/sensitive-action-confirmation', [\Modules\Foundation\Authorization\Http\Controllers\SensitiveActionConfirmationController::class, 'store'])
        ->name('system.sensitive-action-confirmation.store');
    Route::delete('/system/sensitive-action-confirmation', [\Modules\Foundation\Authorization\Http\Controllers\SensitiveActionConfirmationController::class, 'destroy'])
        ->name('system.sensitive-action-confirmation.destroy');
});
