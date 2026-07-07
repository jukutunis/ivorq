<?php

namespace Modules\Finance\Banking\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankingMigrationTargetIntakeStatusEnum;
use Modules\Finance\Banking\Models\BankingMigrationManifestEntry;
use Modules\Finance\Banking\Models\BankingMigrationPlan;
use Modules\Finance\Banking\Models\BankingMigrationTargetIntake;
use Modules\Finance\Banking\Models\ControlledBankAccount;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class BankingMigrationTargetIntakeService
{
    public const PERMISSION_MANAGE = 'finance.banking.migration.manage';
    public const PERMISSION_REVIEW = 'finance.banking.migration.mapping.review';
    public const CONTRACT = 'banking_migration_target_intake_v1';
    public const SOURCE_DOMAIN = 'legacy_banking';
    public const TARGET_DOMAIN = 'controlled_banking';
    public const TARGET_MODEL = 'ControlledBankAccount';
    public const EXECUTION_UNAVAILABLE = 'UNAVAILABLE';
    public const CUTOVER_NOT_AUTHORIZED = 'CUTOVER_NOT_AUTHORIZED';

    public function propose(
        string $migrationPlanId,
        string $manifestEntryId,
        string $controlledBankAccountId,
        ?User $actor
    ): BankingMigrationTargetIntake {
        return DB::transaction(function () use (
            $migrationPlanId,
            $manifestEntryId,
            $controlledBankAccountId,
            $actor
        ): BankingMigrationTargetIntake {
            $actor = $this->resolveProposer($actor);
            $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

            $plan = BankingMigrationPlan::whereKey($migrationPlanId)
                ->where('property_id', $propertyId)
                ->first();

            if (!$plan) {
                throw new DomainException('Migration plan not found or does not belong to the active property.');
            }

            $manifestEntry = BankingMigrationManifestEntry::whereKey($manifestEntryId)
                ->where('migration_plan_id', $plan->id)
                ->first();

            if (!$manifestEntry) {
                throw new DomainException('Manifest entry not found or does not belong to the selected plan.');
            }

            if ($manifestEntry->source_model !== 'BankAccount') {
                throw new DomainException('Only BankAccount manifest entries are eligible for target-intake mapping.');
            }

            $ineligibleStatuses = ['EXCLUDED', 'BLOCKED', 'QUARANTINED'];
            if (in_array($manifestEntry->inventory_status?->value, $ineligibleStatuses, true)) {
                throw new DomainException('Manifest entry is not in an eligible inventory status for mapping.');
            }

            $controlledAccount = ControlledBankAccount::whereKey($controlledBankAccountId)
                ->where('property_id', $propertyId)
                ->where('is_active', true)
                ->first();

            if (!$controlledAccount) {
                throw new DomainException('Controlled bank account not found, does not belong to the active property, or is not active.');
            }

            $existing = BankingMigrationTargetIntake::where('migration_plan_id', $plan->id)
                ->where('manifest_entry_id', $manifestEntry->id)
                ->whereNotIn('status', [
                    BankingMigrationTargetIntakeStatusEnum::ARCHIVED->value,
                    BankingMigrationTargetIntakeStatusEnum::REVIEW_REJECTED->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->fresh();
            }

            $correlationId = (string) Str::ulid();

            $targetIdentityHash = hash('sha256', implode('|', [
                self::CONTRACT,
                $propertyId,
                $controlledAccount->id,
            ]));

            $intake = new BankingMigrationTargetIntake([
                'property_id' => $propertyId,
                'migration_plan_id' => $plan->id,
                'manifest_entry_id' => $manifestEntry->id,
                'source_domain' => self::SOURCE_DOMAIN,
                'source_model' => $manifestEntry->source_model,
                'target_domain' => self::TARGET_DOMAIN,
                'target_model' => self::TARGET_MODEL,
                'controlled_bank_account_id' => $controlledAccount->id,
                'target_identity_hash' => $targetIdentityHash,
                'status' => BankingMigrationTargetIntakeStatusEnum::PROPOSED,
                'correlation_id' => $correlationId,
                'proposal_actor_id' => $actor->id,
                'review_actor_id' => null,
                'review_outcome' => null,
                'review_timestamp' => null,
                'execution_authority' => self::EXECUTION_UNAVAILABLE,
                'cutover_authority' => self::CUTOVER_NOT_AUTHORIZED,
            ]);
            $intake->created_by = $actor->id;
            $intake->updated_by = $actor->id;
            $intake->save();

            return $intake->fresh();
        });
    }

    public function review(
        string $targetIntakeId,
        string $reviewOutcome,
        ?User $actor
    ): BankingMigrationTargetIntake {
        return DB::transaction(function () use (
            $targetIntakeId,
            $reviewOutcome,
            $actor
        ): BankingMigrationTargetIntake {
            $actor = $this->resolveReviewer($actor);
            $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

            $allowedOutcomes = ['REVIEW_ACCEPTED', 'REVIEW_REJECTED'];
            if (!in_array($reviewOutcome, $allowedOutcomes, true)) {
                throw new DomainException('Review outcome must be REVIEW_ACCEPTED or REVIEW_REJECTED.');
            }

            $intake = BankingMigrationTargetIntake::whereKey($targetIntakeId)
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->first();

            if (!$intake) {
                throw new DomainException('Target intake not found or does not belong to the active property.');
            }

            if ($intake->status !== BankingMigrationTargetIntakeStatusEnum::PROPOSED) {
                throw new DomainException('Only PROPOSED target intakes can be reviewed.');
            }

            if ($intake->proposal_actor_id === $actor->id) {
                throw new DomainException('Maker-checker violation: the proposer cannot review their own proposal.');
            }

            $intake->status = $reviewOutcome === 'REVIEW_ACCEPTED'
                ? BankingMigrationTargetIntakeStatusEnum::REVIEW_ACCEPTED
                : BankingMigrationTargetIntakeStatusEnum::REVIEW_REJECTED;
            $intake->review_actor_id = $actor->id;
            $intake->review_outcome = $reviewOutcome;
            $intake->review_timestamp = now();
            $intake->updated_by = $actor->id;
            $intake->save();

            return $intake->fresh();
        });
    }

    public function findForProperty(string $intakeId, string $propertyId): ?BankingMigrationTargetIntake
    {
        return BankingMigrationTargetIntake::whereKey($intakeId)
            ->where('property_id', $propertyId)
            ->first();
    }

    public function listForProperty(string $propertyId): array
    {
        return BankingMigrationTargetIntake::with(['manifestEntry', 'controlledBankAccount'])
            ->where('property_id', $propertyId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->all();
    }

    private function resolveProposer(?User $actor): User
    {
        if (!$actor) {
            throw new DomainException('Authenticated actor is required.');
        }

        if (!$actor->can(self::PERMISSION_MANAGE)) {
            throw new DomainException('Actor lacks migration plan management permission.');
        }

        return $actor;
    }

    private function resolveReviewer(?User $actor): User
    {
        if (!$actor) {
            throw new DomainException('Authenticated actor is required.');
        }

        if (!$actor->can(self::PERMISSION_REVIEW)) {
            throw new DomainException('Actor lacks mapping review permission.');
        }

        return $actor;
    }
}
