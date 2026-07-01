<?php

namespace Modules\Operations\GeneralCashier\Services;

use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\CashCountEvidence;
use Modules\Operations\GeneralCashier\Models\CashReconciliationBaseline;
use Throwable;

class CashCountAndBaselineService
{
    public const RECORD_COUNT_PERMISSION = 'finance.general-cashier.cash-count.record';
    public const CREATE_BASELINE_PERMISSION = 'finance.general-cashier.cash-baseline.create';
    public const COUNT_CONTRACT = 'cash_count_evidence_v1';
    public const BASELINE_CONTRACT = 'cash_reconciliation_baseline_from_count_v1';

    public function recordCashCount(
        string $operationalGlAccountId,
        string $currencyCode,
        mixed $observedAmount,
        string $observedCountDate,
        string $sourceReference,
        ?User $countedBy,
        ?User $recordedBy
    ): CashCountEvidence {
        return DB::transaction(function () use (
            $operationalGlAccountId,
            $currencyCode,
            $observedAmount,
            $observedCountDate,
            $sourceReference,
            $countedBy,
            $recordedBy
        ): CashCountEvidence {
            $recorder = $this->resolveAuthorizedActor($recordedBy, self::RECORD_COUNT_PERMISSION);
            $counter = $this->resolveActiveActor($countedBy, 'Cash Count requires an active counter.');
            $account = $this->resolveCashAccount($operationalGlAccountId);
            $this->assertActorCanAccessProperty($recorder, $account->property_id, 'Cash Count requires recorder property access.');
            $this->assertActorCanAccessProperty($counter, $account->property_id, 'Cash Count requires counter property access.');

            $amount = $this->amountString($observedAmount);
            if ($this->amountToCents($amount) < 0) {
                throw new DomainException('Cash Count observed amount cannot be negative.');
            }

            $countDate = Carbon::parse($observedCountDate)->toDateString();
            $currency = strtoupper($currencyCode);
            if ($currency === '' || strlen($currency) !== 3) {
                throw new DomainException('Cash Count currency must be a three-character code.');
            }

            $sourceReference = trim($sourceReference);
            if ($sourceReference === '') {
                throw new DomainException('Cash Count source reference is required.');
            }

            $identity = [
                'property_id' => $account->property_id,
                'operational_gl_account_id' => $account->id,
                'currency_code' => $currency,
                'observed_count_date' => $countDate,
                'source_reference' => $sourceReference,
            ];
            $identityHash = $this->countIdentityHash($identity, $amount, $counter->id);
            $snapshot = $this->countSnapshot($identity, $amount, $counter->id, $recorder->id);

            $existing = CashCountEvidence::where($identity)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingCountMatches($existing, $amount, $counter->id, $recorder->id, $identityHash);

                return $existing->fresh();
            }

            $count = new CashCountEvidence($identity + [
                'observed_amount' => $amount,
                'counted_by' => $counter->id,
                'recorded_by' => $recorder->id,
                'recorded_at' => now(),
                'source_identity_hash' => $identityHash,
                'source_snapshot' => $snapshot,
            ]);
            $count->created_by = $recorder->id;
            $count->updated_by = $recorder->id;
            $count->save();

            return $count->fresh();
        });
    }

    public function createBaselineFromCount(string $cashCountEvidenceId, ?User $actor): CashReconciliationBaseline
    {
        return DB::transaction(function () use ($cashCountEvidenceId, $actor): CashReconciliationBaseline {
            $actor = $this->resolveAuthorizedActor($actor, self::CREATE_BASELINE_PERMISSION);

            $count = CashCountEvidence::whereKey($cashCountEvidenceId)
                ->lockForUpdate()
                ->firstOrFail();

            $account = $this->resolveCashAccount($count->operational_gl_account_id);
            if (
                $account->property_id !== $count->property_id ||
                strtoupper((string) $count->currency_code) === ''
            ) {
                throw new DomainException('Cash Count evidence account or currency is unavailable for baseline.');
            }

            $this->assertActorCanAccessProperty($actor, $count->property_id, 'Cash Baseline requires active property access.');

            $scopeIdentity = [
                'property_id' => $count->property_id,
                'operational_gl_account_id' => $count->operational_gl_account_id,
                'currency_code' => $count->currency_code,
                'cashbook_boundary_posted_business_date' => $count->observed_count_date->toDateString(),
            ];

            $existingForCount = CashReconciliationBaseline::where('cash_count_evidence_id', $count->id)
                ->lockForUpdate()
                ->first();

            if ($existingForCount) {
                $this->assertExistingBaselineMatches($existingForCount, $count, $actor->id);

                return $existingForCount->fresh();
            }

            $existingScope = CashReconciliationBaseline::where($scopeIdentity)
                ->lockForUpdate()
                ->first();

            if ($existingScope) {
                throw new DomainException('Cash Reconciliation Baseline already exists for this scope boundary.');
            }

            $amount = $this->amountString($count->observed_amount);
            $identityHash = $this->baselineIdentityHash($count, $actor->id);
            $snapshot = $this->baselineSnapshot($count, $actor->id);

            $baseline = new CashReconciliationBaseline($scopeIdentity + [
                'cash_count_evidence_id' => $count->id,
                'baseline_amount' => $amount,
                'baseline_by' => $actor->id,
                'baseline_at' => now(),
                'source_identity_hash' => $identityHash,
                'source_snapshot' => $snapshot,
            ]);
            $baseline->created_by = $actor->id;
            $baseline->updated_by = $actor->id;
            $baseline->save();

            return $baseline->fresh();
        });
    }

    private function resolveAuthorizedActor(?User $actor, string $permission): User
    {
        $freshActor = $this->resolveActiveActor($actor, 'General Cashier cash evidence requires an active actor.');

        try {
            $authorized = $freshActor->can($permission);
        } catch (Throwable) {
            throw new AuthorizationException('General Cashier cash evidence permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('General Cashier cash evidence permission is required.');
        }

        return $freshActor;
    }

    private function resolveActiveActor(?User $actor, string $message): User
    {
        if (!$actor) {
            throw new AuthorizationException($message);
        }

        $freshActor = User::where('id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (!$freshActor) {
            throw new AuthorizationException($message);
        }

        return $freshActor;
    }

    private function resolveCashAccount(string $accountId): Account
    {
        $account = Account::whereKey($accountId)
            ->where('is_active', true)
            ->where('is_cash_equivalent', true)
            ->lockForUpdate()
            ->first();

        if (!$account) {
            throw new DomainException('Active operational cash control account is unavailable.');
        }

        return $account;
    }

    private function assertActorCanAccessProperty(User $actor, string $propertyId, string $message): void
    {
        $hasPropertyAccess = $actor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException($message);
        }
    }

    private function assertExistingCountMatches(
        CashCountEvidence $existing,
        string $amount,
        string $countedBy,
        string $recordedBy,
        string $identityHash
    ): void {
        if (
            $this->amountString($existing->observed_amount) === $amount &&
            $existing->counted_by === $countedBy &&
            $existing->recorded_by === $recordedBy &&
            $existing->recorded_at !== null &&
            $existing->source_identity_hash === $identityHash
        ) {
            return;
        }

        throw new DomainException('Conflicting Cash Count evidence already exists.');
    }

    private function assertExistingBaselineMatches(
        CashReconciliationBaseline $existing,
        CashCountEvidence $count,
        string $actorId
    ): void {
        if (
            $existing->property_id === $count->property_id &&
            $existing->operational_gl_account_id === $count->operational_gl_account_id &&
            $existing->currency_code === $count->currency_code &&
            $this->amountString($existing->baseline_amount) === $this->amountString($count->observed_amount) &&
            $existing->cashbook_boundary_posted_business_date->toDateString() === $count->observed_count_date->toDateString() &&
            $existing->baseline_by === $actorId &&
            $existing->baseline_at !== null
        ) {
            return;
        }

        throw new DomainException('Conflicting Cash Reconciliation Baseline evidence already exists.');
    }

    private function countIdentityHash(array $identity, string $amount, string $countedBy): string
    {
        return hash('sha256', implode('|', [
            self::COUNT_CONTRACT,
            $identity['property_id'],
            $identity['operational_gl_account_id'],
            $identity['currency_code'],
            $amount,
            $identity['observed_count_date'],
            $identity['source_reference'],
            $countedBy,
        ]));
    }

    private function baselineIdentityHash(CashCountEvidence $count, string $actorId): string
    {
        return hash('sha256', implode('|', [
            self::BASELINE_CONTRACT,
            $count->id,
            $count->property_id,
            $count->operational_gl_account_id,
            $count->currency_code,
            $this->amountString($count->observed_amount),
            $count->observed_count_date->toDateString(),
            $actorId,
        ]));
    }

    private function countSnapshot(array $identity, string $amount, string $countedBy, string $recordedBy): array
    {
        return [
            'contract' => self::COUNT_CONTRACT,
            'property_id' => $identity['property_id'],
            'operational_gl_account_id' => $identity['operational_gl_account_id'],
            'currency_code' => $identity['currency_code'],
            'observed_amount' => $amount,
            'observed_count_date' => $identity['observed_count_date'],
            'source_reference' => $identity['source_reference'],
            'counted_by' => $countedBy,
            'recorded_by' => $recordedBy,
        ];
    }

    private function baselineSnapshot(CashCountEvidence $count, string $actorId): array
    {
        return [
            'contract' => self::BASELINE_CONTRACT,
            'cash_count_evidence_id' => $count->id,
            'property_id' => $count->property_id,
            'operational_gl_account_id' => $count->operational_gl_account_id,
            'currency_code' => $count->currency_code,
            'baseline_amount' => $this->amountString($count->observed_amount),
            'cashbook_boundary_posted_business_date' => $count->observed_count_date->toDateString(),
            'baseline_by' => $actorId,
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
}
