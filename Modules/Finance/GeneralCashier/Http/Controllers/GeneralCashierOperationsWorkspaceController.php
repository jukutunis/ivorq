<?php

namespace Modules\Finance\GeneralCashier\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\Banking\Models\BankReturnEvidence;
use Modules\Finance\Banking\Models\ControlledBankStatementLine;
use Modules\Finance\Banking\Services\BankReturnEvidenceService;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Services\SupplierPaymentJournalCandidateService;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\BankSupplierPaymentReversalExecution;
use Modules\Operations\GeneralCashier\Models\CashCountEvidence;
use Modules\Operations\GeneralCashier\Models\CashierPaymentInstrument;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\GeneralCashier\Models\CashReconciliationBaseline;
use Modules\Operations\GeneralCashier\Models\CashReturnEvidence;
use Modules\Operations\GeneralCashier\Models\CashSupplierPaymentReversalExecution;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Modules\Operations\GeneralCashier\Models\PaymentExecutionVoidEvidence;
use Modules\Operations\GeneralCashier\Services\BankSupplierPaymentReversalService;
use Modules\Operations\GeneralCashier\Services\CashCountAndBaselineService;
use Modules\Operations\GeneralCashier\Services\CashReturnEvidenceService;
use Modules\Operations\GeneralCashier\Services\CashSupplierPaymentReversalService;
use Modules\Operations\GeneralCashier\Services\GeneralCashierOperationalFoundationService;
use Modules\Operations\GeneralCashier\Services\PaymentExecutionVoidService;
use Shared\Services\CurrentPropertyService;
use Throwable;

class GeneralCashierOperationsWorkspaceController extends Controller
{
    private const ROUTE = 'finance.general-cashier.operations.index';

    private const CORRECTION_INTENT = 'supplier-payment-correction';

    public function __construct(
        private readonly GeneralCashierOperationalFoundationService $foundationService,
        private readonly CashCountAndBaselineService $cashEvidenceService,
        private readonly PaymentExecutionVoidService $voidService,
        private readonly CashReturnEvidenceService $returnService,
        private readonly CashSupplierPaymentReversalService $reversalService,
        private readonly SupplierPaymentJournalCandidateService $candidateService,
        private readonly BankReturnEvidenceService $bankReturnService,
        private readonly BankSupplierPaymentReversalService $bankReversalService,
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $propertyId = $this->resolvePropertyId($request);
        $actor = $request->user();

        $sessions = CashierSession::where('property_id', $propertyId)
            ->when($actor, fn ($query) => $query->where('cashier_user_id', $actor->id))
            ->orderByDesc('opened_at')->limit(20)->get();
        $instruments = CashierPaymentInstrument::where('property_id', $propertyId)
            ->with('operationalAccount')->orderBy('name')->get();
        $accounts = Account::withoutGlobalScope('property')->where('property_id', $propertyId)
            ->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'is_cash_equivalent']);
        $counts = CashCountEvidence::withoutGlobalScope('property')->where('property_id', $propertyId)
            ->orderByDesc('recorded_at')->limit(50)->get();
        $baselines = CashReconciliationBaseline::withoutGlobalScope('property')->where('property_id', $propertyId)
            ->orderByDesc('baseline_at')->limit(50)->get();

        return Inertia::render('Ivorq/Finance/GeneralCashierOperationsWorkspace', [
            'sessions' => $sessions,
            'instruments' => $instruments,
            'accounts' => $accounts,
            'cash_counts' => $counts,
            'baselines' => $baselines,
            'corrections' => $this->correctionProjection($propertyId),
            'permissions' => [
                'can_open_session' => $actor?->can(GeneralCashierOperationalFoundationService::OPEN_PERMISSION) ?? false,
                'can_configure_instrument' => $actor?->can(GeneralCashierOperationalFoundationService::CONFIGURE_INSTRUMENT_PERMISSION) ?? false,
                'can_record_count' => $actor?->can(CashCountAndBaselineService::RECORD_COUNT_PERMISSION) ?? false,
                'can_create_baseline' => $actor?->can(CashCountAndBaselineService::CREATE_BASELINE_PERMISSION) ?? false,
                'can_void' => $actor?->can(PaymentExecutionVoidService::PERMISSION) ?? false,
                'can_record_cash_return' => $actor?->can(CashReturnEvidenceService::PERMISSION) ?? false,
                'can_reverse_cash' => ($actor?->can(CashSupplierPaymentReversalService::PERMISSION) ?? false)
                    && ($actor?->can(SupplierPaymentJournalCandidateService::PERMISSION) ?? false),
                'can_record_bank_return' => $actor?->can(BankReturnEvidenceService::PERMISSION) ?? false,
                'can_reverse_bank' => ($actor?->can(BankSupplierPaymentReversalService::PERMISSION) ?? false)
                    && ($actor?->can(SupplierPaymentJournalCandidateService::PERMISSION) ?? false),
            ],
        ]);
    }

    public function openSession(Request $request): RedirectResponse
    {
        $this->authorizeAction($request->user(), GeneralCashierOperationalFoundationService::OPEN_PERMISSION);
        $this->resolvePropertyId($request);

        return $this->action(fn () => $this->foundationService->openSession($request->user()), 'Cashier session opened.');
    }

    public function configureInstrument(Request $request): RedirectResponse
    {
        $this->authorizeAction($request->user(), GeneralCashierOperationalFoundationService::CONFIGURE_INSTRUMENT_PERMISSION);
        $this->resolvePropertyId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:CASH,BANK'],
            'operational_gl_account_id' => ['required', 'string', 'size:26'],
        ]);

        return $this->action(
            fn () => $this->foundationService->configureInstrument($data['name'], $data['type'], $data['operational_gl_account_id'], $request->user()),
            'Payment instrument configured.'
        );
    }

    public function recordCount(Request $request): RedirectResponse
    {
        $this->authorizeAction($request->user(), CashCountAndBaselineService::RECORD_COUNT_PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        $data = $request->validate([
            'operational_gl_account_id' => ['required', 'string', 'size:26'],
            'currency_code' => ['required', 'string', 'size:3'],
            'observed_amount' => ['required', 'numeric', 'min:0'],
            'observed_count_date' => ['required', 'date'],
            'source_reference' => ['required', 'string', 'max:255'],
        ]);
        Account::withoutGlobalScope('property')->whereKey($data['operational_gl_account_id'])
            ->where('property_id', $propertyId)->firstOrFail();

        return $this->action(fn () => $this->cashEvidenceService->recordCashCount(
            $data['operational_gl_account_id'], $data['currency_code'], $data['observed_amount'],
            $data['observed_count_date'], $data['source_reference'], $request->user(), $request->user()
        ), 'Cash count evidence recorded.');
    }

    public function createBaseline(Request $request): RedirectResponse
    {
        $this->authorizeAction($request->user(), CashCountAndBaselineService::CREATE_BASELINE_PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        $data = $request->validate(['cash_count_evidence_id' => ['required', 'string', 'size:26']]);
        CashCountEvidence::withoutGlobalScope('property')->whereKey($data['cash_count_evidence_id'])
            ->where('property_id', $propertyId)->firstOrFail();

        return $this->action(fn () => $this->cashEvidenceService->createBaselineFromCount($data['cash_count_evidence_id'], $request->user()), 'Cash reconciliation baseline created.');
    }

    public function voidPayment(Request $request): RedirectResponse
    {
        $this->authorizeAction($request->user(), PaymentExecutionVoidService::PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        if ($redirect = $this->confirmationRedirect($request, $propertyId)) {
            return $redirect;
        }
        $data = $request->validate([
            'payment_execution_id' => ['required', 'string', 'size:26'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        PaymentExecution::withoutGlobalScope('property')->whereKey($data['payment_execution_id'])
            ->where('property_id', $propertyId)->firstOrFail();

        return $this->action(fn () => $this->voidService->void($data['payment_execution_id'], $data['reason'], $request->user()), 'Pre-post payment VOID evidence recorded.');
    }

    public function recordCashReturn(Request $request): RedirectResponse
    {
        $this->authorizeAction($request->user(), CashReturnEvidenceService::PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        if ($redirect = $this->confirmationRedirect($request, $propertyId)) {
            return $redirect;
        }
        $data = $request->validate([
            'posted_journal_entry_id' => ['required', 'string', 'size:26'],
            'source_reference' => ['required', 'string', 'max:255'],
            'observed_return_date' => ['required', 'date'],
        ]);
        JournalEntry::withoutGlobalScope('property')->whereKey($data['posted_journal_entry_id'])
            ->where('property_id', $propertyId)->firstOrFail();

        return $this->action(fn () => $this->returnService->recordCashReturn(
            $data['posted_journal_entry_id'], $data['source_reference'], $data['observed_return_date'], $request->user()
        ), 'Immutable cash return evidence recorded.');
    }

    public function reverseCashPayment(Request $request): RedirectResponse
    {
        $this->authorizeAction($request->user(), CashSupplierPaymentReversalService::PERMISSION);
        $this->authorizeAction($request->user(), SupplierPaymentJournalCandidateService::PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        if ($redirect = $this->confirmationRedirect($request, $propertyId)) {
            return $redirect;
        }
        $data = $request->validate(['cash_return_evidence_id' => ['required', 'string', 'size:26']]);
        CashReturnEvidence::withoutGlobalScope('property')->whereKey($data['cash_return_evidence_id'])
            ->where('property_id', $propertyId)->firstOrFail();

        return $this->action(function () use ($data, $request): void {
            DB::transaction(function () use ($data, $request): void {
                $reversal = $this->reversalService->recordReversalExecution($data['cash_return_evidence_id'], $request->user());
                $this->candidateService->createForCashReversalExecution($reversal->id);
            });
        }, 'Cash supplier-payment reversal and GL candidate created.');
    }

    public function recordBankReturn(Request $request): RedirectResponse
    {
        $this->authorizeAction($request->user(), BankReturnEvidenceService::PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        if ($redirect = $this->confirmationRedirect($request, $propertyId)) {
            return $redirect;
        }
        $data = $request->validate([
            'posted_journal_entry_id' => ['required', 'string', 'size:26'],
            'return_statement_line_id' => ['required', 'string', 'size:26'],
            'reason_code' => ['required', 'in:BANK_RETURN,PAYMENT_REJECTED,BENEFICIARY_REJECTED'],
            'idempotency_key' => ['required', 'string', 'max:96'],
        ]);
        JournalEntry::withoutGlobalScope('property')->whereKey($data['posted_journal_entry_id'])->where('property_id', $propertyId)->firstOrFail();
        ControlledBankStatementLine::withoutGlobalScope('property')->whereKey($data['return_statement_line_id'])->where('property_id', $propertyId)->firstOrFail();

        return $this->action(fn () => $this->bankReturnService->record(
            $data['posted_journal_entry_id'], $data['return_statement_line_id'], $data['reason_code'], $data['idempotency_key'], $request->user()
        ), 'Immutable Banking-owned return evidence recorded.');
    }

    public function reverseBankPayment(Request $request): RedirectResponse
    {
        $this->authorizeAction($request->user(), BankSupplierPaymentReversalService::PERMISSION);
        $this->authorizeAction($request->user(), SupplierPaymentJournalCandidateService::PERMISSION);
        $propertyId = $this->resolvePropertyId($request);
        if ($redirect = $this->confirmationRedirect($request, $propertyId)) {
            return $redirect;
        }
        $data = $request->validate(['bank_return_evidence_id' => ['required', 'string', 'size:26']]);
        BankReturnEvidence::withoutGlobalScope('property')->whereKey($data['bank_return_evidence_id'])->where('property_id', $propertyId)->firstOrFail();

        return $this->action(function () use ($data, $request): void {
            DB::transaction(function () use ($data, $request): void {
                $reversal = $this->bankReversalService->record($data['bank_return_evidence_id'], $request->user());
                $this->candidateService->createForBankReversalExecution($reversal->id);
            });
        }, 'Bank supplier-payment reversal and GL candidate created.');
    }

    private function correctionProjection(string $propertyId): array
    {
        $executions = PaymentExecution::withoutGlobalScope('property')->where('property_id', $propertyId)
            ->with('cashierPaymentInstrument')->orderByDesc('executed_at')->limit(100)->get();
        $voids = PaymentExecutionVoidEvidence::withoutGlobalScope('property')->where('property_id', $propertyId)->get()->keyBy('payment_execution_id');
        $postedJournals = JournalEntry::withoutGlobalScope('property')->where('property_id', $propertyId)
            ->where('source_module', 'GeneralCashier')->where('source_type', 'PaymentExecution')
            ->where('posting_event', CashReturnEvidenceService::POSTING_EVENT)->where('status', 'POSTED')
            ->orderByDesc('posted_at')->get();
        $returns = CashReturnEvidence::withoutGlobalScope('property')->where('property_id', $propertyId)
            ->orderByDesc('recorded_at')->get();
        $reversals = CashSupplierPaymentReversalExecution::withoutGlobalScope('property')->where('property_id', $propertyId)
            ->orderByDesc('reversed_at')->get();
        $candidateByReversal = JournalCandidate::withoutGlobalScope('property')->where('property_id', $propertyId)
            ->where('source_type', SupplierPaymentJournalCandidateService::REVERSAL_SOURCE_TYPE)->get()->keyBy('source_id');
        $bankReturnLines = ControlledBankStatementLine::withoutGlobalScope('property')->where('property_id', $propertyId)
            ->where('direction', ControlledBankStatementLineDirectionEnum::INFLOW->value)->orderByDesc('statement_date')->limit(100)->get();
        $bankReturns = BankReturnEvidence::withoutGlobalScope('property')->where('property_id', $propertyId)->orderByDesc('recorded_at')->get();
        $bankReversals = BankSupplierPaymentReversalExecution::withoutGlobalScope('property')->where('property_id', $propertyId)->orderByDesc('reversed_at')->get();
        $bankCandidateByReversal = JournalCandidate::withoutGlobalScope('property')->where('property_id', $propertyId)
            ->where('source_type', SupplierPaymentJournalCandidateService::BANK_REVERSAL_SOURCE_TYPE)->get()->keyBy('source_id');

        return [
            'executions' => $executions->map(fn ($execution) => [
                'id' => $execution->id, 'amount' => (string) $execution->source_amount,
                'currency_code' => $execution->currency_code, 'executed_at' => $execution->executed_at?->toIso8601String(),
                'instrument_type' => $execution->cashierPaymentInstrument?->type?->value,
                'voided' => $voids->has($execution->id),
            ])->values(),
            'posted_cash_journals' => $postedJournals->map(fn ($journal) => [
                'id' => $journal->id, 'reference' => $journal->reference,
                'posting_date' => $journal->posting_date?->toDateString(), 'source_id' => $journal->source_id,
            ])->values(),
            'cash_returns' => $returns->map(fn ($return) => [
                'id' => $return->id, 'payment_execution_id' => $return->payment_execution_id,
                'posted_journal_entry_id' => $return->posted_journal_entry_id, 'amount' => (string) $return->return_amount,
                'currency_code' => $return->currency_code, 'source_reference' => $return->source_reference,
                'observed_return_date' => $return->observed_return_date?->toDateString(),
                'reversed' => $reversals->contains('cash_return_evidence_id', $return->id),
            ])->values(),
            'reversals' => $reversals->map(fn ($reversal) => [
                'id' => $reversal->id, 'cash_return_evidence_id' => $reversal->cash_return_evidence_id,
                'amount' => (string) $reversal->reversal_amount, 'currency_code' => $reversal->currency_code,
                'candidate_id' => $candidateByReversal->get($reversal->id)?->id,
                'candidate_status' => $candidateByReversal->get($reversal->id)?->status?->value,
            ])->values(),
            'bank_return_lines' => $bankReturnLines->map(fn ($line) => [
                'id' => $line->id, 'controlled_bank_account_id' => $line->controlled_bank_account_id,
                'amount' => (string) $line->amount, 'currency_code' => $line->currency_code,
                'statement_date' => $line->statement_date?->toDateString(), 'external_reference' => $line->external_reference,
            ])->values(),
            'bank_returns' => $bankReturns->map(fn ($return) => [
                'id' => $return->id, 'payment_execution_id' => $return->payment_execution_id,
                'amount' => (string) $return->return_amount, 'currency_code' => $return->currency_code,
                'reason_code' => $return->reason_code, 'return_statement_line_id' => $return->return_statement_line_id,
                'reversed' => $bankReversals->contains('bank_return_evidence_id', $return->id),
            ])->values(),
            'bank_reversals' => $bankReversals->map(fn ($reversal) => [
                'id' => $reversal->id, 'bank_return_evidence_id' => $reversal->bank_return_evidence_id,
                'amount' => (string) $reversal->reversal_amount, 'currency_code' => $reversal->currency_code,
                'candidate_id' => $bankCandidateByReversal->get($reversal->id)?->id,
                'candidate_status' => $bankCandidateByReversal->get($reversal->id)?->status?->value,
            ])->values(),
        ];
    }

    private function confirmationRedirect(Request $request, string $propertyId): ?RedirectResponse
    {
        $companyId = $request->session()->get('active_company_id');
        if ($this->confirmationService->hasValidConfirmation($request->user(), self::CORRECTION_INTENT, $companyId, $propertyId)) {
            return null;
        }

        return redirect()->route('system.sensitive-action-confirmation.index', ['intent' => self::CORRECTION_INTENT])
            ->with('error', 'Sensitive action confirmation is required before supplier payment correction.');
    }

    private function authorizeAction(?User $user, string $permission): void
    {
        if (! $user || ! $user->can($permission)) {
            abort(403, 'Unauthorized.');
        }
    }

    private function action(callable $action, string $message): RedirectResponse
    {
        try {
            $action();

            return redirect()->route(self::ROUTE)->with('success', $message);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return redirect()->route(self::ROUTE)->with('error', $exception->getMessage());
        }
    }

    private function resolvePropertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id') ?? app(CurrentPropertyService::class)->resolveOrFail();
        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }
}
