<?php

namespace Modules\Finance\Banking\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\Banking\Models\ControlledBankAccount;
use Modules\Finance\Banking\Models\ControlledBankStatementLine;
use Modules\Finance\Banking\Models\BankPaymentReconciliation;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Services\ManualBankReconciliationService;
use Modules\Finance\Payables\Models\PaymentProposalItem;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierPaymentInstrumentTypeEnum;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierPaymentInstrument;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Modules\Operations\GeneralCashier\Services\PaymentExecutionService;
use Shared\Services\CurrentPropertyService;
use Throwable;

class BankingOperationsWorkspaceController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $propertyId = $this->resolvePropertyId($request);
        $actor = $request->user();

        $bankAccounts = ControlledBankAccount::with(['operationalAccount', 'registrar'])
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->orderBy('account_name')
            ->limit(20)
            ->get()
            ->map(fn (ControlledBankAccount $account) => [
                'id' => $account->id,
                'bank_name' => $account->bank_name,
                'account_name' => $account->account_name,
                'external_account_reference' => $account->external_account_reference,
                'currency_code' => $account->currency_code,
            ])
            ->values()
            ->all();

        $accountIds = array_column($bankAccounts, 'id');

        $statementLines = [];
        if (!empty($accountIds)) {
            $statementLines = ControlledBankStatementLine::whereIn('controlled_bank_account_id', $accountIds)
                ->where('property_id', $propertyId)
                ->where('direction', ControlledBankStatementLineDirectionEnum::OUTFLOW->value)
                ->orderByDesc('statement_date')
                ->limit(50)
                ->get()
                ->map(fn (ControlledBankStatementLine $line) => [
                    'id' => $line->id,
                    'controlled_bank_account_id' => $line->controlled_bank_account_id,
                    'amount' => (string) ($line->amount ?? '0'),
                    'currency_code' => $line->currency_code,
                    'statement_date' => $line->statement_date,
                    'external_reference' => $line->external_reference,
                    'vendor_reference' => $line->vendor_reference,
                ])
                ->values()
                ->all();
        }

        $bankExecutionEvidence = PaymentExecution::with(['cashierSession', 'cashierPaymentInstrument', 'controlledBankAccount', 'controlledBankStatementLine'])
            ->where('property_id', $propertyId)
            ->whereNotNull('controlled_bank_account_id')
            ->whereNotNull('controlled_bank_statement_line_id')
            ->orderByDesc('executed_at')
            ->limit(50)
            ->get()
            ->map(fn (PaymentExecution $execution) => [
                'id' => $execution->id,
                'payment_proposal_id' => $execution->payment_proposal_id,
                'payment_proposal_item_id' => $execution->payment_proposal_item_id,
                'source_amount' => (string) ($execution->source_amount ?? '0'),
                'currency_code' => $execution->currency_code,
                'executed_at' => $execution->executed_at?->toIso8601String(),
                'controlled_bank_account_id' => $execution->controlled_bank_account_id,
                'controlled_bank_statement_line_id' => $execution->controlled_bank_statement_line_id,
                'source_journal_entry_id' => $execution->source_journal_entry_id,
            ])
            ->values()
            ->all();

        $reconciliationEvidence = BankPaymentReconciliation::with(['bankAccount', 'statementLine', 'paymentExecution', 'postedJournalEntry', 'reconciler'])
            ->where('property_id', $propertyId)
            ->orderByDesc('reconciled_at')
            ->limit(50)
            ->get()
            ->map(fn (BankPaymentReconciliation $rec) => [
                'id' => $rec->id,
                'controlled_bank_account_id' => $rec->controlled_bank_account_id,
                'controlled_bank_statement_line_id' => $rec->controlled_bank_statement_line_id,
                'payment_execution_id' => $rec->payment_execution_id,
                'posted_journal_entry_id' => $rec->posted_journal_entry_id,
                'payment_amount' => (string) ($rec->payment_amount ?? '0'),
                'statement_amount' => (string) ($rec->statement_amount ?? '0'),
                'difference_amount' => (string) ($rec->difference_amount ?? '0'),
                'currency_code' => $rec->currency_code,
                'status' => $rec->status?->value,
                'reconciled_at' => $rec->reconciled_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $reconciliationSessions = ReconciliationSession::with('bankAccount')
            ->where('property_id', $propertyId)
            ->withCount('matches')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (ReconciliationSession $session) => [
                'id' => $session->id,
                'status' => $session->status?->value,
                'bank_account_id' => $session->bank_account_id,
                'bank_account_name' => $session->bankAccount->account_name ?? null,
                'bank_name' => $session->bankAccount->bank_name ?? null,
                'currency_code' => $session->bankAccount->currency_code ?? null,
                'statement_date_start' => $session->statement_date_start?->toDateString(),
                'statement_date_end' => $session->statement_date_end?->toDateString(),
                'matches_count' => $session->matches_count ?? 0,
                'completed_at' => $session->completed_at?->toIso8601String(),
                'finalized_at' => $session->finalized_at?->toIso8601String(),
                'created_at' => $session->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $bankExecutionContext = $this->projectBankExecutionContext($propertyId, $actor);

        $controlledReadiness = $this->projectControlledReadiness($propertyId);

        $canExecuteBank = $actor instanceof User && $actor->can(PaymentExecutionService::PERMISSION);
        $canReconcile = $actor instanceof User && $actor->can(ManualBankReconciliationService::PERMISSION);
        $canViewReconciliationSessions = $actor instanceof User && $actor->can('banking.reconciliation.view');

        return Inertia::render('Ivorq/Finance/BankingOperationsWorkspace', [
            'bank_accounts' => array_values($bankAccounts),
            'statement_lines' => array_values($statementLines),
            'bank_execution_evidence' => array_values($bankExecutionEvidence),
            'reconciliation_evidence' => array_values($reconciliationEvidence),
            'reconciliation_sessions' => array_values($reconciliationSessions),
            'bank_execution_context' => $bankExecutionContext,
            'controlled_readiness' => array_values($controlledReadiness),
            'domain_sections' => [
                'controlled' => [
                    'label' => 'Controlled Banking — operational source evidence',
                    'description' => 'Accounts, statement lines, payment executions, and manual reconciliation records managed through the controlled Banking source path.',
                ],
                'legacy' => [
                    'label' => 'Legacy Banking — isolated historical/compatibility evidence',
                    'description' => 'Reconciliation sessions and match records from the legacy Banking module. Not linked to controlled Banking records.',
                ],
            ],
            'permissions' => [
                'can_execute_bank' => $canExecuteBank,
                'can_reconcile_bank' => $canReconcile,
                'can_view_reconciliation_sessions' => $canViewReconciliationSessions,
            ],
        ]);
    }

    public function execute(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->can(PaymentExecutionService::PERMISSION)) {
            abort(403, 'Unauthorized.');
        }
        $propertyId = $this->resolvePropertyId($request);

        $validated = $request->validate([
            'payment_proposal_item_id' => ['required', 'string', 'size:26'],
            'cashier_session_id' => ['required', 'string', 'size:26'],
            'bank_payment_instrument_id' => ['required', 'string', 'size:26'],
            'controlled_bank_account_id' => ['required', 'string', 'size:26'],
            'controlled_bank_statement_line_id' => ['required', 'string', 'size:26'],
        ]);

        $item = PaymentProposalItem::whereKey($validated['payment_proposal_item_id'])
            ->where('property_id', $propertyId)
            ->firstOrFail();

        $session = CashierSession::whereKey($validated['cashier_session_id'])
            ->where('property_id', $propertyId)
            ->firstOrFail();

        $instrument = CashierPaymentInstrument::whereKey($validated['bank_payment_instrument_id'])
            ->where('property_id', $propertyId)
            ->firstOrFail();

        $bankAccount = ControlledBankAccount::whereKey($validated['controlled_bank_account_id'])
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->firstOrFail();

        $statementLine = ControlledBankStatementLine::whereKey($validated['controlled_bank_statement_line_id'])
            ->where('property_id', $propertyId)
            ->firstOrFail();

        $companyId = $request->session()->get('active_company_id');
        $confirmationService = app(SensitiveActionConfirmationService::class);
        if (!$confirmationService->hasValidConfirmation($user, 'bank-payment-execution', $companyId, $propertyId)) {
            return redirect()
                ->route('system.sensitive-action-confirmation.index', ['intent' => 'bank-payment-execution'])
                ->with('error', 'Sensitive action confirmation is required before executing bank payments.');
        }

        $service = app(PaymentExecutionService::class);

        try {
            $service->recordConfirmedBankExecution(
                $validated['payment_proposal_item_id'],
                $validated['cashier_session_id'],
                $validated['bank_payment_instrument_id'],
                $validated['controlled_bank_account_id'],
                $validated['controlled_bank_statement_line_id'],
                $user
            );

            return redirect()
                ->route('finance.banking.operations.index')
                ->with('success', 'Bank payment execution recorded.');
        } catch (Throwable $exception) {
            return redirect()
                ->route('finance.banking.operations.index')
                ->with('error', $exception->getMessage());
        }
    }

    public function reconcile(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->can(ManualBankReconciliationService::PERMISSION)) {
            abort(403, 'Unauthorized.');
        }
        $propertyId = $this->resolvePropertyId($request);

        $validated = $request->validate([
            'posted_journal_entry_id' => ['required', 'string', 'size:26'],
            'controlled_bank_statement_line_id' => ['required', 'string', 'size:26'],
        ]);

        $journal = \Modules\Finance\GeneralLedger\Models\JournalEntry::whereKey($validated['posted_journal_entry_id'])
            ->where('property_id', $propertyId)
            ->firstOrFail();

        $statementLine = ControlledBankStatementLine::whereKey($validated['controlled_bank_statement_line_id'])
            ->where('property_id', $propertyId)
            ->firstOrFail();

        $reconciliationService = app(ManualBankReconciliationService::class);

        try {
            $reconciliationService->reconcilePostedBankPayment(
                $validated['posted_journal_entry_id'],
                $validated['controlled_bank_statement_line_id'],
                $user
            );

            return redirect()
                ->route('finance.banking.operations.index')
                ->with('success', 'Bank payment reconciliation recorded.');
        } catch (Throwable $exception) {
            return redirect()
                ->route('finance.banking.operations.index')
                ->with('error', $exception->getMessage());
        }
    }

    private function projectBankExecutionContext(string $propertyId, $actor): array
    {
        $executedItemIds = PaymentExecution::where('property_id', $propertyId)
            ->whereNotNull('controlled_bank_account_id')
            ->whereNotNull('controlled_bank_statement_line_id')
            ->pluck('payment_proposal_item_id')
            ->unique()
            ->values()
            ->all();

        $eligibleItems = PaymentProposalItem::with(['proposal', 'supplierInvoice'])
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->whereNotIn('id', $executedItemIds)
            ->whereHas('proposal', function ($query) {
                $query->where('status', 'APPROVED');
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (PaymentProposalItem $item) => [
                'id' => $item->id,
                'proposal_number' => $item->proposal->proposal_number ?? null,
                'invoice_number' => $item->supplierInvoice->invoice_number ?? null,
                'amount' => (string) ($item->requested_payment_amount ?? $item->source_amount ?? '0'),
                'currency_code' => $item->currency_code,
                'vendor_id' => $item->vendor_id,
            ])
            ->values()
            ->all();

        $sessions = [];
        if ($actor) {
            $sessions = CashierSession::where('property_id', $propertyId)
                ->where('cashier_user_id', $actor->id)
                ->where('status', CashierSessionStatusEnum::OPEN->value)
                ->orderByDesc('opened_at')
                ->limit(5)
                ->get()
                ->map(fn (CashierSession $session) => [
                    'id' => $session->id,
                    'status' => $session->status->value,
                    'opened_at' => $session->opened_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        $instruments = CashierPaymentInstrument::where('property_id', $propertyId)
            ->where('type', CashierPaymentInstrumentTypeEnum::BANK->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (CashierPaymentInstrument $instrument) => [
                'id' => $instrument->id,
                'name' => $instrument->name,
                'type' => $instrument->type->value,
            ])
            ->values()
            ->all();

        $bankAccounts = ControlledBankAccount::where('property_id', $propertyId)
            ->where('is_active', true)
            ->orderBy('account_name')
            ->limit(20)
            ->get()
            ->map(fn (ControlledBankAccount $account) => [
                'id' => $account->id,
                'account_name' => $account->account_name,
                'bank_name' => $account->bank_name,
                'currency_code' => $account->currency_code,
            ])
            ->values()
            ->all();

        $accountIdsForStatement = array_column($bankAccounts, 'id');
        $statementLines = [];
        if (!empty($accountIdsForStatement)) {
            $statementLines = ControlledBankStatementLine::whereIn('controlled_bank_account_id', $accountIdsForStatement)
                ->where('property_id', $propertyId)
                ->where('direction', ControlledBankStatementLineDirectionEnum::OUTFLOW->value)
                ->orderByDesc('statement_date')
                ->limit(50)
                ->get()
                ->map(fn (ControlledBankStatementLine $line) => [
                    'id' => $line->id,
                    'controlled_bank_account_id' => $line->controlled_bank_account_id,
                    'amount' => (string) ($line->amount ?? '0'),
                    'currency_code' => $line->currency_code,
                    'statement_date' => $line->statement_date,
                    'external_reference' => $line->external_reference,
                ])
                ->values()
                ->all();
        }

        return [
            'eligible_items' => array_values($eligibleItems),
            'bank_sessions' => array_values($sessions),
            'bank_instruments' => array_values($instruments),
            'bank_accounts' => array_values($bankAccounts),
            'statement_lines' => array_values($statementLines),
        ];
    }

    private function projectControlledReadiness(string $propertyId): array
    {
        $accounts = ControlledBankAccount::where('property_id', $propertyId)
            ->where('is_active', true)
            ->get();

        $readiness = [];

        foreach ($accounts as $account) {
            $statementLineCount = ControlledBankStatementLine::where('controlled_bank_account_id', $account->id)
                ->where('property_id', $propertyId)
                ->where('direction', ControlledBankStatementLineDirectionEnum::OUTFLOW->value)
                ->count();

            $executionCount = PaymentExecution::where('property_id', $propertyId)
                ->where('controlled_bank_account_id', $account->id)
                ->whereNotNull('controlled_bank_statement_line_id')
                ->count();

            $reconciledCount = BankPaymentReconciliation::where('property_id', $propertyId)
                ->where('controlled_bank_account_id', $account->id)
                ->count();

            $readiness[] = [
                'account_id' => $account->id,
                'account_name' => $account->account_name,
                'bank_name' => $account->bank_name,
                'currency_code' => $account->currency_code,
                'statement_line_count' => $statementLineCount,
                'execution_count' => $executionCount,
                'reconciled_count' => $reconciledCount,
            ];
        }

        return $readiness;
    }

    private function resolvePropertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();

        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }
}
