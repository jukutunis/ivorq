<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', fn () => redirect('/frontdesk'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
});

use App\Http\Controllers\Ivorq\EngineeringController;
use App\Http\Controllers\Ivorq\FinanceController;
use App\Http\Controllers\Ivorq\FrontDeskController;
use App\Http\Controllers\Ivorq\HousekeepingController;
use App\Http\Controllers\Ivorq\HRISController;
use App\Http\Controllers\Ivorq\LogbookEntryController;
use App\Http\Controllers\Ivorq\LogbookEntryFollowUpResolutionController;
use App\Http\Controllers\Ivorq\LogbookEntrySelfCorrectionController;
use App\Http\Controllers\Ivorq\ShiftLogController;
use Modules\Finance\Banking\Http\Controllers\BankingMigrationPlanController;
use Modules\Finance\Banking\Http\Controllers\BankingOperationsWorkspaceController;
use Modules\Finance\FxReference\Http\Controllers\ExchangeRateEvidenceWorkspaceController;
use Modules\Finance\FxReference\Http\Controllers\FxAdjustmentControlWorkspaceController;
use Modules\Finance\FxReference\Http\Controllers\FxBreakGlassController;
use Modules\Finance\GeneralCashier\Http\Controllers\CashbookEvidenceWorkspaceController;
use Modules\Finance\GeneralCashier\Http\Controllers\GeneralCashierOperationsWorkspaceController;
use Modules\Finance\GeneralLedger\Http\Controllers\FinancialPeriodControlWorkspaceController;
use Modules\Finance\GeneralLedger\Http\Controllers\FinancialReportingWorkspaceController;
use Modules\Finance\GeneralLedger\Http\Controllers\GeneralLedgerOperationsWorkspaceController;
use Modules\Finance\GeneralLedger\Http\Controllers\GrniControlWorkspaceController;
use Modules\Finance\Payables\Http\Controllers\ApGrniSettlementControlWorkspaceController;
use Modules\Finance\Payables\Http\Controllers\PaymentProposalControlWorkspaceController;
use Modules\Finance\Payables\Http\Controllers\SupplierInvoiceControlWorkspaceController;
use Modules\Foundation\Authorization\Http\Controllers\FxOperationalRoleAssignmentController;
use Modules\Foundation\Authorization\Http\Controllers\SensitiveActionConfirmationController;
use Modules\Foundation\Notification\Http\Controllers\NotificationController;
use Modules\Operations\FrontDesk\Http\Controllers\EngineeringAvailabilityDependencyController;
use Modules\Operations\FrontDesk\Http\Controllers\HousekeepingReadinessDependencyController;
use Modules\SalesAndEventManagement\Http\Controllers\BEODistributionController;

Route::middleware(['auth', 'active.property'])->group(function () {
    Route::get('/frontdesk', fn () => redirect('/frontdesk/arrivals'));
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
        Route::get('/engineering-availability/{room}', [EngineeringAvailabilityDependencyController::class, 'show'])
            ->name('frontdesk.engineering-availability.show');
        Route::get('/housekeeping-readiness/{room}', [HousekeepingReadinessDependencyController::class, 'show'])
            ->name('frontdesk.housekeeping-readiness.show');
        Route::post('/stays/{stay}/departure-preparation-events', [FrontDeskController::class, 'createDeparturePreparationEvent'])
            ->name('frontdesk.stays.departure-preparation-events.store');
        Route::get('/stays/{stay}/departure-preparation-events', [FrontDeskController::class, 'departurePreparationEvents'])
            ->name('frontdesk.stays.departure-preparation-events.index');
        Route::post('/stays/{stay}/departure-operational-handovers', [FrontDeskController::class, 'createDepartureOperationalHandover'])
            ->name('frontdesk.stays.departure-operational-handovers.store');
        Route::get('/stays/{stay}/departure-operational-handovers', [FrontDeskController::class, 'departureOperationalHandover'])
            ->name('frontdesk.stays.departure-operational-handovers.index');
        Route::post('/stays/{stay}/departure-closure-readiness', [FrontDeskController::class, 'createDepartureClosureReadiness'])
            ->name('frontdesk.stays.departure-closure-readiness.store');
        Route::get('/stays/{stay}/departure-closure-readiness', [FrontDeskController::class, 'departureClosureReadiness'])
            ->name('frontdesk.stays.departure-closure-readiness.index');
        Route::post('/stays/{stay}/departure-checkout-eligibility', [FrontDeskController::class, 'createDepartureCheckoutEligibility'])
            ->name('frontdesk.stays.departure-checkout-eligibility.store');
        Route::get('/stays/{stay}/departure-checkout-eligibility', [FrontDeskController::class, 'departureCheckoutEligibility'])
            ->name('frontdesk.stays.departure-checkout-eligibility.index');
        Route::post('/stays/{stay}/departure-checkout-authorization', [FrontDeskController::class, 'createDepartureCheckoutAuthorization'])
            ->name('frontdesk.stays.departure-checkout-authorization.store');
        Route::get('/stays/{stay}/departure-checkout-authorization', [FrontDeskController::class, 'departureCheckoutAuthorization'])
            ->name('frontdesk.stays.departure-checkout-authorization.index');
        Route::post('/stays/{stay}/departure-checkout-final-review', [FrontDeskController::class, 'createDepartureCheckoutFinalReview'])
            ->name('frontdesk.stays.departure-checkout-final-review.store');
        Route::get('/stays/{stay}/departure-checkout-final-review', [FrontDeskController::class, 'departureCheckoutFinalReview'])
            ->name('frontdesk.stays.departure-checkout-final-review.index');
        Route::get('/stays/{stay}/departure-checkout-execution-boundary', [FrontDeskController::class, 'departureCheckoutExecutionBoundary'])
            ->name('frontdesk.stays.departure-checkout-execution-boundary.index');
        Route::post('/stays/{stay}/checkout-confirmation', [FrontDeskController::class, 'prepareCheckoutConfirmation'])
            ->name('frontdesk.stays.checkout-confirmation.store');
        Route::post('/stays/{stay}/checkout-execution', [FrontDeskController::class, 'executeCheckout'])
            ->name('frontdesk.stays.checkout-execution.store');
    });

    Route::get('/housekeeping', fn () => redirect('/housekeeping/room-board'));
    Route::prefix('housekeeping')->group(function () {
        Route::get('/room-board', [HousekeepingController::class, 'roomBoard']);
        Route::get('/attendant-status', [HousekeepingController::class, 'attendantStatus']);
        Route::get('/inspections', [HousekeepingController::class, 'inspections']);
        Route::get('/lost-found', [HousekeepingController::class, 'lostFound']);
        Route::get('/room-readiness', [HousekeepingController::class, 'roomReadiness']);
    });

    Route::get('/engineering', fn () => redirect('/engineering/work-orders'));
    Route::prefix('engineering')->group(function () {
        Route::get('/work-orders', [EngineeringController::class, 'workOrders']);
        Route::get('/preventive-maintenance', [EngineeringController::class, 'preventiveMaintenance']);
        Route::get('/asset-registry', [EngineeringController::class, 'assetRegistry']);
        Route::get('/technician-schedule', [EngineeringController::class, 'technicianSchedule']);
    });

    Route::get('/finance', fn () => redirect('/finance/revenue-cash'));
    Route::prefix('finance')->group(function () {
        Route::get('/revenue-cash', [FinanceController::class, 'revenueCash']);
        Route::get('/accounts-payable', [FinanceController::class, 'accountsPayable']);
        Route::get('/payables/ap-grni-settlement-control', [ApGrniSettlementControlWorkspaceController::class, 'index'])
            ->name('finance.payables.ap-grni-settlement-control');
        Route::post('/payables/ap-grni-settlement-control/payment-proposals', [ApGrniSettlementControlWorkspaceController::class, 'createDraft'])
            ->name('finance.payables.ap-grni-settlement-control.payment-proposals.create');
        Route::post('/payables/ap-grni-settlement-control/payment-proposals/{paymentProposal}/cancel', [ApGrniSettlementControlWorkspaceController::class, 'cancelDraft'])
            ->name('finance.payables.ap-grni-settlement-control.payment-proposals.cancel');
        Route::post('/payables/settlement-allocations', [ApGrniSettlementControlWorkspaceController::class, 'allocate'])
            ->name('finance.payables.settlement-allocations.allocate');
        Route::get('/payables/payment-proposals', [PaymentProposalControlWorkspaceController::class, 'index'])
            ->name('finance.payables.payment-proposals.index');
        Route::post('/payables/payment-proposals/{proposal}/approve', [PaymentProposalControlWorkspaceController::class, 'approve'])
            ->name('finance.payables.payment-proposals.approve');
        Route::post('/payables/payment-proposals/{proposal}/reject', [PaymentProposalControlWorkspaceController::class, 'reject'])
            ->name('finance.payables.payment-proposals.reject');
        Route::get('/payables/cashbook-evidence', [CashbookEvidenceWorkspaceController::class, 'index'])
            ->name('finance.payables.cashbook-evidence.index');
        Route::post('/payables/cash-payment-execute', [CashbookEvidenceWorkspaceController::class, 'execute'])
            ->name('finance.payables.cash-payment-execute.execute');
        Route::post('/payables/cash-reconciliation', [CashbookEvidenceWorkspaceController::class, 'reconcile'])
            ->name('finance.payables.cash-reconciliation.reconcile');
        Route::get('/general-cashier/operations', [GeneralCashierOperationsWorkspaceController::class, 'index'])
            ->name('finance.general-cashier.operations.index');
        Route::post('/general-cashier/sessions/open', [GeneralCashierOperationsWorkspaceController::class, 'openSession'])
            ->name('finance.general-cashier.sessions.open');
        Route::post('/general-cashier/instruments', [GeneralCashierOperationsWorkspaceController::class, 'configureInstrument'])
            ->name('finance.general-cashier.instruments.store');
        Route::post('/general-cashier/cash-counts', [GeneralCashierOperationsWorkspaceController::class, 'recordCount'])
            ->name('finance.general-cashier.cash-counts.store');
        Route::post('/general-cashier/baselines', [GeneralCashierOperationsWorkspaceController::class, 'createBaseline'])
            ->name('finance.general-cashier.baselines.store');
        Route::post('/general-cashier/corrections/void', [GeneralCashierOperationsWorkspaceController::class, 'voidPayment'])
            ->name('finance.general-cashier.corrections.void');
        Route::post('/general-cashier/corrections/cash-return', [GeneralCashierOperationsWorkspaceController::class, 'recordCashReturn'])
            ->name('finance.general-cashier.corrections.cash-return');
        Route::post('/general-cashier/corrections/cash-reversal', [GeneralCashierOperationsWorkspaceController::class, 'reverseCashPayment'])
            ->name('finance.general-cashier.corrections.cash-reversal');
        Route::post('/general-cashier/corrections/bank-return', [GeneralCashierOperationsWorkspaceController::class, 'recordBankReturn'])
            ->name('finance.general-cashier.corrections.bank-return');
        Route::post('/general-cashier/corrections/bank-reversal', [GeneralCashierOperationsWorkspaceController::class, 'reverseBankPayment'])
            ->name('finance.general-cashier.corrections.bank-reversal');
        Route::get('/banking/operations', [BankingOperationsWorkspaceController::class, 'index'])
            ->name('finance.banking.operations.index');
        Route::post('/banking/bank-payment-execute', [BankingOperationsWorkspaceController::class, 'execute'])
            ->name('finance.banking.bank-payment-execute.execute');
        Route::post('/banking/bank-reconciliation', [BankingOperationsWorkspaceController::class, 'reconcile'])
            ->name('finance.banking.bank-reconciliation.reconcile');
        Route::get('/banking/migration', [BankingMigrationPlanController::class, 'index'])
            ->name('finance.banking.migration.index');
        Route::post('/banking/migration/plan', [BankingMigrationPlanController::class, 'create'])
            ->name('finance.banking.migration.plan.create');
        Route::post('/banking/migration/plan/{plan}/request-dry-run', [BankingMigrationPlanController::class, 'requestDryRun'])
            ->name('finance.banking.migration.plan.request-dry-run');
        Route::post('/banking/migration/plan/{plan}/execute-dry-run', [BankingMigrationPlanController::class, 'executeDryRun'])
            ->name('finance.banking.migration.plan.execute-dry-run');
        Route::post('/banking/migration/target-intake/propose', [BankingMigrationPlanController::class, 'propose'])
            ->name('finance.banking.migration.target-intake.propose');
        Route::post('/banking/migration/target-intake/{intake}/review', [BankingMigrationPlanController::class, 'review'])
            ->name('finance.banking.migration.target-intake.review');
        Route::post('/banking/migration/pilot-authorization/request', [BankingMigrationPlanController::class, 'requestPilotAuthorization'])
            ->name('finance.banking.migration.pilot-authorization.request');
        Route::post('/banking/migration/pilot-authorization/{pilotAuth}/review', [BankingMigrationPlanController::class, 'reviewPilotAuthorization'])
            ->name('finance.banking.migration.pilot-authorization.review');
        Route::post('/banking/migration/pilot-authorization/{pilotAuth}/execute', [BankingMigrationPlanController::class, 'execute'])
            ->name('finance.banking.migration.pilot-authorization.execute');
        Route::get('/payables/supplier-invoices', [SupplierInvoiceControlWorkspaceController::class, 'index'])
            ->name('finance.payables.supplier-invoices.index');
        Route::post('/payables/supplier-invoices', [SupplierInvoiceControlWorkspaceController::class, 'store'])
            ->name('finance.payables.supplier-invoices.store');
        Route::post('/payables/supplier-invoices/{invoice}/approve', [SupplierInvoiceControlWorkspaceController::class, 'approve'])
            ->name('finance.payables.supplier-invoices.approve');
        Route::post('/payables/supplier-invoices/{invoice}/reject', [SupplierInvoiceControlWorkspaceController::class, 'reject'])
            ->name('finance.payables.supplier-invoices.reject');
        Route::post('/payables/supplier-invoices/{invoice}/resolve-exception', [SupplierInvoiceControlWorkspaceController::class, 'resolveException'])
            ->name('finance.payables.supplier-invoices.resolve-exception');
        Route::get('/accounts-receivable', [FinanceController::class, 'accountsReceivable']);
        Route::get('/budget-watch', [FinanceController::class, 'budgetWatch']);
        Route::get('/general-ledger/grni-control', [GrniControlWorkspaceController::class, 'index'])
            ->name('finance.general-ledger.grni-control');
        Route::get('/general-ledger/operations', [GeneralLedgerOperationsWorkspaceController::class, 'index'])
            ->name('finance.general-ledger.operations');
        Route::get('/general-ledger/reports', [FinancialReportingWorkspaceController::class, 'index'])
            ->name('finance.general-ledger.reports');
        Route::get('/general-ledger/period-control', [FinancialPeriodControlWorkspaceController::class, 'index'])
            ->name('finance.general-ledger.period-control');
        Route::post('/general-ledger/period-control/initialize', [FinancialPeriodControlWorkspaceController::class, 'initialize'])
            ->name('finance.general-ledger.period-control.initialize');
        Route::get('/fx-rates', [ExchangeRateEvidenceWorkspaceController::class, 'index'])
            ->name('finance.fx-rates.index');
        Route::post('/fx-rates', [ExchangeRateEvidenceWorkspaceController::class, 'record'])
            ->name('finance.fx-rates.record');
        Route::post('/fx-rates/{evidence}/approve', [ExchangeRateEvidenceWorkspaceController::class, 'approve'])
            ->name('finance.fx-rates.approve');
        Route::post('/fx-rates/{evidence}/reject', [ExchangeRateEvidenceWorkspaceController::class, 'reject'])
            ->name('finance.fx-rates.reject');
        Route::get('/fx-adjustments', [FxAdjustmentControlWorkspaceController::class, 'index'])
            ->name('finance.fx-adjustments.index');
        Route::get('/fx-access-management', [FxOperationalRoleAssignmentController::class, 'index'])
            ->name('finance.fx-operational-role-assignments.index');
        Route::post('/fx-access-management', [FxOperationalRoleAssignmentController::class, 'store'])
            ->name('finance.fx-operational-role-assignments.store');
        Route::get('/fx-break-glass', [FxBreakGlassController::class, 'index'])
            ->name('finance.fx-break-glass.index');
        Route::post('/fx-break-glass', [FxBreakGlassController::class, 'store'])
            ->name('finance.fx-break-glass.store');
        Route::delete('/fx-break-glass', [FxBreakGlassController::class, 'destroy'])
            ->name('finance.fx-break-glass.destroy');
        Route::post('/fx-adjustments/candidates', [FxAdjustmentControlWorkspaceController::class, 'create'])
            ->name('finance.fx-adjustments.candidates.create');
        Route::post('/fx-adjustments/candidates/{candidate}/review', [FxAdjustmentControlWorkspaceController::class, 'review'])
            ->name('finance.fx-adjustments.candidates.review');
        Route::post('/fx-adjustments/candidates/{candidate}/materialize', [FxAdjustmentControlWorkspaceController::class, 'materialize'])
            ->name('finance.fx-adjustments.candidates.materialize');
        Route::post('/fx-adjustments/journals/{journalEntry}/authorize-finalization', [FxAdjustmentControlWorkspaceController::class, 'authorizeFinalization'])
            ->name('finance.fx-adjustments.journals.authorize-finalization');
        Route::post('/fx-adjustments/journals/{journalEntry}/post', [FxAdjustmentControlWorkspaceController::class, 'post'])
            ->name('finance.fx-adjustments.journals.post');
        Route::post('/general-ledger/grni-control/candidates/{candidate}/approve', [GrniControlWorkspaceController::class, 'approve'])
            ->name('finance.general-ledger.grni-control.candidates.approve');
        Route::post('/general-ledger/grni-control/candidates/{candidate}/reject', [GrniControlWorkspaceController::class, 'reject'])
            ->name('finance.general-ledger.grni-control.candidates.reject');
        Route::post('/general-ledger/grni-control/candidates/{candidate}/materialize', [GrniControlWorkspaceController::class, 'materialize'])
            ->name('finance.general-ledger.grni-control.candidates.materialize');
        Route::post('/general-ledger/grni-control/journals/{journalEntry}/authorize-finalization', [GrniControlWorkspaceController::class, 'authorizeFinalization'])
            ->name('finance.general-ledger.grni-control.journals.authorize-finalization');
        Route::post('/general-ledger/grni-control/journals/{journalEntry}/post', [GrniControlWorkspaceController::class, 'post'])
            ->name('finance.general-ledger.grni-control.journals.post');
    });

    Route::get('/hris', fn () => redirect('/hris/attendance'));
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

    Route::get('/api/v1/operations/logbook-entries', [LogbookEntryController::class, 'index']);
    Route::post('/api/v1/operations/logbook-entries', [LogbookEntryController::class, 'store']);
    Route::patch('/api/v1/operations/logbook-entries/{logbookEntry}', [LogbookEntryController::class, 'update']);
    Route::post('/api/v1/operations/logbook-entries/{logbookEntry}/submit', [LogbookEntryController::class, 'submit']);
    Route::post('/api/v1/operations/logbook-entries/{logbookEntry}/follow-up-resolution', [LogbookEntryFollowUpResolutionController::class, 'resolve']);
    Route::post('/api/v1/operations/logbook-entries/{logbookEntry}/self-corrections', [LogbookEntrySelfCorrectionController::class, 'append']);

    // BEO Distribution — Sprint 14.8.5
    Route::post('/api/v1/sales-events/beo-distributions', [BEODistributionController::class, 'distribute']);
    Route::post('/api/v1/sales-events/beo-distributions/{distribution}/cancel', [BEODistributionController::class, 'cancel']);
    Route::post('/api/v1/sales-events/beo-acknowledgements/{acknowledgement}/acknowledge', [BEODistributionController::class, 'acknowledge']);
    Route::post('/api/v1/sales-events/beo-acknowledgements/{acknowledgement}/reject', [BEODistributionController::class, 'reject']);
    Route::get('/api/v1/sales-events/beo-distributions/{distribution}', [BEODistributionController::class, 'show']);

    Route::get('/system/sensitive-action-confirmation', [SensitiveActionConfirmationController::class, 'index'])
        ->name('system.sensitive-action-confirmation.index');
    Route::post('/system/sensitive-action-confirmation', [SensitiveActionConfirmationController::class, 'store'])
        ->name('system.sensitive-action-confirmation.store');
    Route::delete('/system/sensitive-action-confirmation', [SensitiveActionConfirmationController::class, 'destroy'])
        ->name('system.sensitive-action-confirmation.destroy');
});
