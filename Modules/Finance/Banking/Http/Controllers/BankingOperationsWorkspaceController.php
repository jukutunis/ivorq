<?php

namespace Modules\Finance\Banking\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\Banking\Models\ControlledBankAccount;
use Modules\Finance\Banking\Models\ControlledBankStatementLine;
use Modules\Finance\Banking\Models\BankPaymentReconciliation;
use Modules\Finance\Banking\Services\ManualBankReconciliationService;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Modules\Operations\GeneralCashier\Services\PaymentExecutionService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

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

        $canExecuteBank = $actor instanceof User && $actor->can(PaymentExecutionService::PERMISSION);
        $canReconcile = $actor instanceof User && $actor->can(ManualBankReconciliationService::PERMISSION);

        return Inertia::render('Ivorq/Finance/BankingOperationsWorkspace', [
            'bank_accounts' => array_values($bankAccounts),
            'statement_lines' => array_values($statementLines),
            'bank_execution_evidence' => array_values($bankExecutionEvidence),
            'reconciliation_evidence' => array_values($reconciliationEvidence),
            'permissions' => [
                'can_execute_bank' => $canExecuteBank,
                'can_reconcile_bank' => $canReconcile,
            ],
        ]);
    }

    private function resolvePropertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();

        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }
}
