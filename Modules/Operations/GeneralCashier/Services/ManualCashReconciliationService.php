<?php

namespace Modules\Operations\GeneralCashier\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashReconciliationStatusEnum;
use Modules\Operations\GeneralCashier\Enums\CashbookTransactionDirectionEnum;
use Modules\Operations\GeneralCashier\Models\CashCountEvidence;
use Modules\Operations\GeneralCashier\Models\CashReconciliation;
use Modules\Operations\GeneralCashier\Models\CashReconciliationBaseline;
use Modules\Operations\GeneralCashier\Models\CashbookTransaction;
use Throwable;

class ManualCashReconciliationService
{
    public const PERMISSION = 'finance.general-cashier.cash-reconciliation.perform';
    public const CONTRACT = 'manual_cash_reconciliation_v1';

    public function reconcile(
        string $cashReconciliationBaselineId,
        string $endingCashCountEvidenceId,
        ?User $actor
    ): CashReconciliation {
        return DB::transaction(function () use (
            $cashReconciliationBaselineId,
            $endingCashCountEvidenceId,
            $actor
        ): CashReconciliation {
            $actor = $this->resolveAuthorizedActor($actor);

            $baseline = CashReconciliationBaseline::whereKey($cashReconciliationBaselineId)
                ->lockForUpdate()
                ->firstOrFail();

            $endingCount = CashCountEvidence::whereKey($endingCashCountEvidenceId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $baseline->property_id);
            $this->assertEndingCountMatchesBaseline($baseline, $endingCount);
            $this->assertNoOverlappingScope($baseline, $endingCount);

            $scope = $this->cashbookScope($baseline, $endingCount);
            $amounts = $this->deriveAmounts($baseline, $endingCount, $scope);
            $status = $amounts['difference_cents'] === 0
                ? CashReconciliationStatusEnum::RECONCILED
                : CashReconciliationStatusEnum::EXCEPTION;
            $identityHash = $this->sourceIdentityHash($baseline, $endingCount, $amounts, $scope);
            $snapshot = $this->sourceSnapshot($baseline, $endingCount, $amounts, $scope, $actor->id, $status);

            $existing = CashReconciliation::where('cash_reconciliation_baseline_id', $baseline->id)
                ->orWhere('ending_cash_count_evidence_id', $endingCount->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingReconciliationMatches($existing, $baseline, $endingCount, $amounts, $identityHash, $status);

                return $existing->fresh();
            }

            $reconciliation = new CashReconciliation([
                'cash_reconciliation_baseline_id' => $baseline->id,
                'ending_cash_count_evidence_id' => $endingCount->id,
                'property_id' => $baseline->property_id,
                'operational_gl_account_id' => $baseline->operational_gl_account_id,
                'currency_code' => $baseline->currency_code,
                'scope_start_exclusive_date' => $baseline->cashbook_boundary_posted_business_date->toDateString(),
                'scope_end_inclusive_date' => $endingCount->observed_count_date->toDateString(),
                'baseline_amount' => $amounts['baseline_amount'],
                'cashbook_inflow_amount' => $amounts['inflow_amount'],
                'cashbook_outflow_amount' => $amounts['outflow_amount'],
                'expected_amount' => $amounts['expected_amount'],
                'observed_amount' => $amounts['observed_amount'],
                'difference_amount' => $amounts['difference_amount'],
                'status' => $status->value,
                'reconciled_by' => $actor->id,
                'reconciled_at' => now(),
                'source_identity_hash' => $identityHash,
                'source_snapshot' => $snapshot,
            ]);
            $reconciliation->created_by = $actor->id;
            $reconciliation->updated_by = $actor->id;
            $reconciliation->save();

            return $reconciliation->fresh();
        });
    }

    private function resolveAuthorizedActor(?User $actor): User
    {
        if (!$actor) {
            throw new AuthorizationException('Cash Reconciliation requires an active actor.');
        }

        $freshActor = User::where('id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (!$freshActor) {
            throw new AuthorizationException('Cash Reconciliation requires an active actor.');
        }

        try {
            $authorized = $freshActor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Cash Reconciliation permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Cash Reconciliation permission is required.');
        }

        return $freshActor;
    }

    private function assertActorCanAccessProperty(User $actor, string $propertyId): void
    {
        $hasPropertyAccess = $actor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('Cash Reconciliation requires active property access.');
        }
    }

    private function assertEndingCountMatchesBaseline(
        CashReconciliationBaseline $baseline,
        CashCountEvidence $endingCount
    ): void {
        if (
            $endingCount->property_id !== $baseline->property_id ||
            $endingCount->operational_gl_account_id !== $baseline->operational_gl_account_id ||
            $endingCount->currency_code !== $baseline->currency_code
        ) {
            throw new DomainException('Ending Cash Count conflicts with baseline scope.');
        }

        if ($endingCount->observed_count_date->lte($baseline->cashbook_boundary_posted_business_date)) {
            throw new DomainException('Ending Cash Count must occur after the baseline boundary.');
        }
    }

    private function assertNoOverlappingScope(
        CashReconciliationBaseline $baseline,
        CashCountEvidence $endingCount
    ): void {
        $start = $baseline->cashbook_boundary_posted_business_date->toDateString();
        $end = $endingCount->observed_count_date->toDateString();

        $overlapExists = CashReconciliation::where('property_id', $baseline->property_id)
            ->where('operational_gl_account_id', $baseline->operational_gl_account_id)
            ->where('currency_code', $baseline->currency_code)
            ->where('scope_start_exclusive_date', '<', $end)
            ->where('scope_end_inclusive_date', '>', $start)
            ->where(function ($query) use ($baseline, $endingCount): void {
                $query->where('cash_reconciliation_baseline_id', '<>', $baseline->id)
                    ->orWhere('ending_cash_count_evidence_id', '<>', $endingCount->id);
            })
            ->exists();

        if ($overlapExists) {
            throw new DomainException('Cash Reconciliation scope overlaps existing evidence.');
        }
    }

    private function cashbookScope(
        CashReconciliationBaseline $baseline,
        CashCountEvidence $endingCount
    ): array {
        return CashbookTransaction::where('property_id', $baseline->property_id)
            ->where('operational_gl_account_id', $baseline->operational_gl_account_id)
            ->where('currency_code', $baseline->currency_code)
            ->where('posted_business_date', '>', $baseline->cashbook_boundary_posted_business_date->toDateString())
            ->where('posted_business_date', '<=', $endingCount->observed_count_date->toDateString())
            ->orderBy('posted_business_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();
    }

    private function deriveAmounts(
        CashReconciliationBaseline $baseline,
        CashCountEvidence $endingCount,
        array $scope
    ): array {
        $baselineCents = $this->amountToCents($baseline->baseline_amount);
        $inflowCents = 0;
        $outflowCents = 0;

        foreach ($scope as $transaction) {
            $amount = $this->amountToCents($transaction->amount);

            if ($transaction->direction === CashbookTransactionDirectionEnum::INFLOW) {
                $inflowCents += $amount;
                continue;
            }

            if ($transaction->direction === CashbookTransactionDirectionEnum::OUTFLOW) {
                $outflowCents += $amount;
                continue;
            }

            throw new DomainException('CashbookTransaction direction is unsupported for reconciliation.');
        }

        $expectedCents = $baselineCents + $inflowCents - $outflowCents;
        $observedCents = $this->amountToCents($endingCount->observed_amount);
        $differenceCents = $observedCents - $expectedCents;

        return [
            'baseline_cents' => $baselineCents,
            'inflow_cents' => $inflowCents,
            'outflow_cents' => $outflowCents,
            'expected_cents' => $expectedCents,
            'observed_cents' => $observedCents,
            'difference_cents' => $differenceCents,
            'baseline_amount' => $this->centsString($baselineCents),
            'inflow_amount' => $this->centsString($inflowCents),
            'outflow_amount' => $this->centsString($outflowCents),
            'expected_amount' => $this->centsString($expectedCents),
            'observed_amount' => $this->centsString($observedCents),
            'difference_amount' => $this->centsString($differenceCents),
        ];
    }

    private function assertExistingReconciliationMatches(
        CashReconciliation $existing,
        CashReconciliationBaseline $baseline,
        CashCountEvidence $endingCount,
        array $amounts,
        string $identityHash,
        CashReconciliationStatusEnum $status
    ): void {
        if (
            $existing->cash_reconciliation_baseline_id === $baseline->id &&
            $existing->ending_cash_count_evidence_id === $endingCount->id &&
            $this->amountString($existing->expected_amount) === $amounts['expected_amount'] &&
            $this->amountString($existing->observed_amount) === $amounts['observed_amount'] &&
            $this->amountString($existing->difference_amount) === $amounts['difference_amount'] &&
            $existing->status === $status &&
            $existing->source_identity_hash === $identityHash
        ) {
            return;
        }

        throw new DomainException('Conflicting Cash Reconciliation evidence already exists.');
    }

    private function sourceIdentityHash(
        CashReconciliationBaseline $baseline,
        CashCountEvidence $endingCount,
        array $amounts,
        array $scope
    ): string {
        return hash('sha256', implode('|', [
            self::CONTRACT,
            $baseline->id,
            $endingCount->id,
            $baseline->property_id,
            $baseline->operational_gl_account_id,
            $baseline->currency_code,
            $amounts['expected_amount'],
            $amounts['observed_amount'],
            implode(',', array_map(fn (CashbookTransaction $transaction): string => $transaction->id, $scope)),
        ]));
    }

    private function sourceSnapshot(
        CashReconciliationBaseline $baseline,
        CashCountEvidence $endingCount,
        array $amounts,
        array $scope,
        string $actorId,
        CashReconciliationStatusEnum $status
    ): array {
        return [
            'contract' => self::CONTRACT,
            'baseline_id' => $baseline->id,
            'ending_cash_count_evidence_id' => $endingCount->id,
            'scope_start_exclusive_date' => $baseline->cashbook_boundary_posted_business_date->toDateString(),
            'scope_end_inclusive_date' => $endingCount->observed_count_date->toDateString(),
            'cashbook_transaction_ids' => array_map(fn (CashbookTransaction $transaction): string => $transaction->id, $scope),
            'baseline_amount' => $amounts['baseline_amount'],
            'cashbook_inflow_amount' => $amounts['inflow_amount'],
            'cashbook_outflow_amount' => $amounts['outflow_amount'],
            'expected_amount' => $amounts['expected_amount'],
            'observed_amount' => $amounts['observed_amount'],
            'difference_amount' => $amounts['difference_amount'],
            'status' => $status->value,
            'reconciled_by' => $actorId,
        ];
    }

    private function amountToCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function amountString(mixed $amount): string
    {
        return number_format(((float) $amount), 2, '.', '');
    }

    private function centsString(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign . number_format($abs / 100, 2, '.', '');
    }
}
