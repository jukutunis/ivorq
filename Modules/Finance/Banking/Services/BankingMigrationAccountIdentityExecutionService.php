<?php

namespace Modules\Finance\Banking\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankingMigrationAccountIdentityExecutionOutcomeEnum;
use Modules\Finance\Banking\Enums\BankingMigrationExceptionCodeEnum;
use Modules\Finance\Banking\Enums\BankingMigrationExceptionSeverityEnum;
use Modules\Finance\Banking\Enums\BankingMigrationPilotAuthorizationStatusEnum;
use Modules\Finance\Banking\Enums\BankingMigrationTargetIntakeStatusEnum;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Models\BankingMigrationAccountIdentityExecution;
use Modules\Finance\Banking\Models\BankingMigrationExceptionQuarantine;
use Modules\Finance\Banking\Models\BankingMigrationManifestEntry;
use Modules\Finance\Banking\Models\BankingMigrationPilotAuthorization;
use Modules\Finance\Banking\Models\BankingMigrationPlan;
use Modules\Finance\Banking\Models\BankingMigrationTargetIntake;
use Modules\Finance\Banking\Models\ControlledBankAccount;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class BankingMigrationAccountIdentityExecutionService
{
    public const CONTRACT = 'banking_migration_account_identity_execution_v1';
    public const SOURCE_DOMAIN = 'legacy_banking';
    public const SOURCE_MODEL = 'BankAccount';
    public const TARGET_DOMAIN = 'controlled_banking';
    public const TARGET_MODEL = 'ControlledBankAccount';
    public const CONFIRMATION_INTENT = 'banking-migration-account-identity-pilot-execution';
    public const PERMISSION_EXECUTE = 'finance.banking.migration.pilot.execution.execute';
    public const CUTOVER_NOT_AUTHORIZED = 'CUTOVER_NOT_AUTHORIZED';

    public function execute(
        string $pilotAuthorizationId,
        ?User $actor
    ): BankingMigrationAccountIdentityExecution {
        $actor = $this->resolveExecutor($actor);
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $companyId = session()->get('active_company_id');

        $confirmationService = app(SensitiveActionConfirmationService::class);
        $confirmationService->requireValidConfirmation($actor, self::CONFIRMATION_INTENT, $companyId, $propertyId);

        $pilotAuth = BankingMigrationPilotAuthorization::whereKey($pilotAuthorizationId)
            ->where('property_id', $propertyId)
            ->first();

        if (!$pilotAuth) {
            throw new DomainException('Pilot authorization not found or does not belong to the active property.');
        }

        if ($pilotAuth->status !== BankingMigrationPilotAuthorizationStatusEnum::REVIEW_ACCEPTED) {
            throw new DomainException('Pilot authorization must be REVIEW_ACCEPTED before execution.');
        }

        $targetIntake = BankingMigrationTargetIntake::whereKey($pilotAuth->target_intake_id)
            ->where('property_id', $propertyId)
            ->first();

        if (!$targetIntake) {
            throw new DomainException('Target intake not found or does not belong to the active property.');
        }

        if ($targetIntake->status !== BankingMigrationTargetIntakeStatusEnum::REVIEW_ACCEPTED) {
            throw new DomainException('Target intake must be REVIEW_ACCEPTED before execution.');
        }

        $plan = BankingMigrationPlan::whereKey($pilotAuth->migration_plan_id)
            ->where('property_id', $propertyId)
            ->first();

        if (!$plan) {
            throw new DomainException('Migration plan not found or does not belong to the active property.');
        }

        $manifestEntry = BankingMigrationManifestEntry::whereKey($pilotAuth->manifest_entry_id)
            ->where('migration_plan_id', $plan->id)
            ->first();

        if (!$manifestEntry) {
            throw new DomainException('Manifest entry not found or does not belong to the plan.');
        }

        if ($manifestEntry->source_model !== self::SOURCE_MODEL) {
            throw new DomainException('Only BankAccount manifest entries are eligible for execution.');
        }

        if ($targetIntake->proposal_actor_id === $actor->id) {
            throw new DomainException('Actor-separation violation: the target intake proposer cannot execute.');
        }

        if ($targetIntake->review_actor_id === $actor->id) {
            throw new DomainException('Actor-separation violation: the target intake reviewer cannot execute.');
        }

        if ($pilotAuth->request_actor_id === $actor->id) {
            throw new DomainException('Actor-separation violation: the pilot authorization requester cannot execute.');
        }

        if ($pilotAuth->review_actor_id === $actor->id) {
            throw new DomainException('Actor-separation violation: the pilot authorization reviewer cannot execute.');
        }

        $sourceAccount = BankAccount::whereKey($manifestEntry->source_ulid)
            ->where('property_id', $propertyId)
            ->first();

        if (!$sourceAccount) {
            throw new DomainException('Legacy source bank account not found or does not belong to the active property.');
        }

        $controlledAccount = ControlledBankAccount::whereKey($targetIntake->controlled_bank_account_id)
            ->where('property_id', $propertyId)
            ->first();

        if (!$controlledAccount) {
            throw new DomainException('Controlled target bank account not found or does not belong to the active property.');
        }

        if (!$controlledAccount->is_active) {
            throw new DomainException('Controlled target account is not active.');
        }

        $currentSourceSnapshotHash = $this->buildSafeSourceSnapshotHash(
            $manifestEntry->source_model,
            $manifestEntry->source_ulid,
            $manifestEntry->source_property_id,
            $sourceAccount->created_at,
            $sourceAccount->updated_at
        );

        if (!hash_equals($manifestEntry->source_snapshot_hash, $currentSourceSnapshotHash)) {
            $this->createConflictQuarantine(
                $plan->id,
                $manifestEntry->id,
                BankingMigrationExceptionCodeEnum::EXECUTION_SOURCE_SNAPSHOT_CHANGED,
                BankingMigrationExceptionSeverityEnum::BLOCKER,
                $manifestEntry->source_domain,
                $manifestEntry->source_model,
                $manifestEntry->source_ulid,
                $manifestEntry->source_property_id,
                $actor
            );

            throw new DomainException('Source snapshot has changed since the manifest entry was created.');
        }

        $unresolvedQuarantine = BankingMigrationExceptionQuarantine::where('migration_plan_id', $plan->id)
            ->where('is_resolved', false)
            ->where('source_ulid', $manifestEntry->source_ulid)
            ->exists();

        if ($unresolvedQuarantine) {
            throw new DomainException('Unresolved exception quarantine blocks execution for this source identity.');
        }

        $sourceIdentityHash = $manifestEntry->source_identity_hash;
        $targetIdentityHash = $targetIntake->target_identity_hash;

        $idempotencyKey = hash('sha256', implode('|', [
            self::CONTRACT,
            $propertyId,
            $manifestEntry->source_ulid,
            $controlledAccount->id,
        ]));

        return DB::transaction(function () use (
            $propertyId,
            $plan,
            $manifestEntry,
            $targetIntake,
            $pilotAuth,
            $controlledAccount,
            $sourceIdentityHash,
            $targetIdentityHash,
            $currentSourceSnapshotHash,
            $idempotencyKey,
            $actor,
            $confirmationService,
            $companyId
        ): BankingMigrationAccountIdentityExecution {
            $existing = BankingMigrationAccountIdentityExecution::where('property_id', $propertyId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }

            $alreadyClaimedSource = BankingMigrationAccountIdentityExecution::where('property_id', $propertyId)
                ->where('source_domain', self::SOURCE_DOMAIN)
                ->where('source_model', self::SOURCE_MODEL)
                ->where('source_ulid', $manifestEntry->source_ulid)
                ->first();

            if ($alreadyClaimedSource && $alreadyClaimedSource->target_ulid !== $controlledAccount->id) {
                $this->createConflictQuarantine(
                    $plan->id,
                    $manifestEntry->id,
                    BankingMigrationExceptionCodeEnum::EXECUTION_SOURCE_ALREADY_CLAIMED,
                    BankingMigrationExceptionSeverityEnum::BLOCKER,
                    self::SOURCE_DOMAIN,
                    self::SOURCE_MODEL,
                    $manifestEntry->source_ulid,
                    $manifestEntry->source_property_id,
                    $actor
                );

                throw new DomainException('Source identity has already been executed with a different target.');
            }

            $alreadyClaimedTarget = BankingMigrationAccountIdentityExecution::where('property_id', $propertyId)
                ->where('target_domain', self::TARGET_DOMAIN)
                ->where('target_model', self::TARGET_MODEL)
                ->where('target_ulid', $controlledAccount->id)
                ->first();

            if ($alreadyClaimedTarget && $alreadyClaimedTarget->source_ulid !== $manifestEntry->source_ulid) {
                $this->createConflictQuarantine(
                    $plan->id,
                    $manifestEntry->id,
                    BankingMigrationExceptionCodeEnum::EXECUTION_TARGET_ALREADY_CLAIMED,
                    BankingMigrationExceptionSeverityEnum::BLOCKER,
                    self::SOURCE_DOMAIN,
                    self::SOURCE_MODEL,
                    $manifestEntry->source_ulid,
                    $manifestEntry->source_property_id,
                    $actor
                );

                throw new DomainException('Target identity has already been claimed by a different source.');
            }

            $correlationId = (string) Str::ulid();
            $now = Carbon::now();

            $confirmationMetadata = $confirmationService->confirmationMetadataFor($actor, self::CONFIRMATION_INTENT, $companyId, $propertyId);

            $execution = new BankingMigrationAccountIdentityExecution([
                'property_id' => $propertyId,
                'migration_plan_id' => $plan->id,
                'manifest_entry_id' => $manifestEntry->id,
                'target_intake_id' => $targetIntake->id,
                'pilot_authorization_id' => $pilotAuth->id,
                'source_domain' => self::SOURCE_DOMAIN,
                'source_model' => self::SOURCE_MODEL,
                'source_ulid' => $manifestEntry->source_ulid,
                'source_property_id' => $manifestEntry->source_property_id,
                'source_identity_hash' => $sourceIdentityHash,
                'source_snapshot_hash' => $currentSourceSnapshotHash,
                'target_domain' => self::TARGET_DOMAIN,
                'target_model' => self::TARGET_MODEL,
                'target_ulid' => $controlledAccount->id,
                'target_property_id' => $propertyId,
                'target_identity_hash' => $targetIdentityHash,
                'outcome' => BankingMigrationAccountIdentityExecutionOutcomeEnum::ACCOUNT_IDENTITY_LINEAGE_EXECUTED,
                'execution_actor_id' => $actor->id,
                'pilot_auth_reviewer_id' => $pilotAuth->review_actor_id,
                'correlation_id' => $correlationId,
                'idempotency_key' => $idempotencyKey,
                'confirmation_evidence' => [
                    'intent' => self::CONFIRMATION_INTENT,
                    'confirmed_at' => $confirmationMetadata['confirmed_at'] ?? null,
                    'context_property_id' => $propertyId,
                    'context_plan_id' => $plan->id,
                    'context_manifest_entry_id' => $manifestEntry->id,
                    'context_target_intake_id' => $targetIntake->id,
                    'context_pilot_authorization_id' => $pilotAuth->id,
                ],
                'executed_at' => $now,
            ]);
            $execution->created_by = $actor->id;
            $execution->updated_by = $actor->id;
            $execution->save();

            return $execution->fresh();
        });
    }

    private function buildSafeSourceSnapshotHash(
        string $sourceModel,
        string $sourceUid,
        string $sourcePropertyId,
        mixed $createdAt,
        mixed $updatedAt
    ): string {
        $createdAtStr = $createdAt !== null
            ? ($createdAt instanceof \DateTimeInterface ? $createdAt->format('Y-m-d H:i:s') : (string) $createdAt)
            : 'null';
        $updatedAtStr = $updatedAt !== null
            ? ($updatedAt instanceof \DateTimeInterface ? $updatedAt->format('Y-m-d H:i:s') : (string) $updatedAt)
            : 'null';

        return hash('sha256', implode('|', [
            $sourceModel,
            $sourceUid,
            $sourcePropertyId,
            $createdAtStr,
            $updatedAtStr,
        ]));
    }

    private function createConflictQuarantine(
        string $planId,
        string $manifestEntryId,
        BankingMigrationExceptionCodeEnum $code,
        BankingMigrationExceptionSeverityEnum $severity,
        string $sourceDomain,
        string $sourceModel,
        string $sourceUid,
        string $sourcePropertyId,
        User $actor
    ): void {
        $existing = BankingMigrationExceptionQuarantine::where('migration_plan_id', $planId)
            ->where('exception_code', $code->value)
            ->where('source_domain', $sourceDomain)
            ->where('source_model', $sourceModel)
            ->where('source_ulid', $sourceUid)
            ->first();

        if ($existing) {
            return;
        }

        $quarantine = new BankingMigrationExceptionQuarantine([
            'migration_plan_id' => $planId,
            'manifest_entry_id' => $manifestEntryId,
            'exception_code' => $code,
            'severity' => BankingMigrationExceptionSeverityEnum::from($severity),
            'source_domain' => $sourceDomain,
            'source_model' => $sourceModel,
            'source_ulid' => $sourceUid,
            'source_property_id' => $sourcePropertyId,
            'correlation_id' => (string) Str::ulid(),
            'is_resolved' => false,
        ]);
        $quarantine->created_by = $actor->id;
        $quarantine->updated_by = $actor->id;
        $quarantine->save();
    }

    private function resolveExecutor(?User $actor): User
    {
        if (!$actor) {
            throw new DomainException('Authenticated actor is required.');
        }

        if (!$actor->can(self::PERMISSION_EXECUTE)) {
            throw new DomainException('Actor lacks execution permission.');
        }

        return $actor;
    }
}
