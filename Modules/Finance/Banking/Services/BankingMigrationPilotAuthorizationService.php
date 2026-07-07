<?php

namespace Modules\Finance\Banking\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankingMigrationPilotAuthorizationStatusEnum;
use Modules\Finance\Banking\Enums\BankingMigrationTargetIntakeStatusEnum;
use Modules\Finance\Banking\Models\BankingMigrationManifestEntry;
use Modules\Finance\Banking\Models\BankingMigrationPilotAuthorization;
use Modules\Finance\Banking\Models\BankingMigrationPlan;
use Modules\Finance\Banking\Models\BankingMigrationTargetIntake;
use Modules\Finance\Banking\Models\ControlledBankAccount;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class BankingMigrationPilotAuthorizationService
{
    public const PERMISSION_REQUEST = 'finance.banking.migration.manage';
    public const PERMISSION_REVIEW = 'finance.banking.migration.pilot.authorization.review';
    public const CONTRACT = 'banking_migration_pilot_authorization_v1';
    public const AUTHORIZATION_SCOPE = 'account_identity_pilot_only';
    public const EXECUTION_NOT_IMPLEMENTED = 'MIGRATION_EXECUTION_NOT_IMPLEMENTED';
    public const CUTOVER_NOT_AUTHORIZED = 'CUTOVER_NOT_AUTHORIZED';

    public function request(
        string $targetIntakeId,
        ?User $actor
    ): BankingMigrationPilotAuthorization {
        return DB::transaction(function () use (
            $targetIntakeId,
            $actor
        ): BankingMigrationPilotAuthorization {
            $actor = $this->resolveRequester($actor);
            $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

            $targetIntake = BankingMigrationTargetIntake::whereKey($targetIntakeId)
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->first();

            if (!$targetIntake) {
                throw new DomainException('Target intake not found or does not belong to the active property.');
            }

            if ($targetIntake->status !== BankingMigrationTargetIntakeStatusEnum::REVIEW_ACCEPTED) {
                throw new DomainException('Only REVIEW_ACCEPTED target intakes are eligible for pilot authorization request.');
            }

            $plan = BankingMigrationPlan::whereKey($targetIntake->migration_plan_id)
                ->where('property_id', $propertyId)
                ->first();

            if (!$plan) {
                throw new DomainException('Migration plan not found or does not belong to the active property.');
            }

            $manifestEntry = BankingMigrationManifestEntry::whereKey($targetIntake->manifest_entry_id)
                ->where('migration_plan_id', $plan->id)
                ->first();

            if (!$manifestEntry) {
                throw new DomainException('Manifest entry not found or does not belong to the plan.');
            }

            if ($manifestEntry->source_model !== 'BankAccount') {
                throw new DomainException('Only BankAccount manifest entries are eligible for pilot authorization.');
            }

            $controlledAccount = ControlledBankAccount::whereKey($targetIntake->controlled_bank_account_id)
                ->where('property_id', $propertyId)
                ->where('is_active', true)
                ->first();

            if (!$controlledAccount) {
                throw new DomainException('Controlled bank account not found, does not belong to the active property, or is not active.');
            }

            $idempotencyKey = hash('sha256', implode('|', [
                self::CONTRACT,
                $propertyId,
                $actor->id,
                $targetIntake->id,
            ]));

            $existing = BankingMigrationPilotAuthorization::where('target_intake_id', $targetIntake->id)
                ->whereNotIn('status', [
                    BankingMigrationPilotAuthorizationStatusEnum::ARCHIVED->value,
                    BankingMigrationPilotAuthorizationStatusEnum::REVIEW_REJECTED->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->fresh();
            }

            $correlationId = (string) Str::ulid();

            $pilotAuth = new BankingMigrationPilotAuthorization([
                'property_id' => $propertyId,
                'migration_plan_id' => $plan->id,
                'manifest_entry_id' => $manifestEntry->id,
                'target_intake_id' => $targetIntake->id,
                'authorization_scope' => self::AUTHORIZATION_SCOPE,
                'status' => BankingMigrationPilotAuthorizationStatusEnum::REQUESTED,
                'correlation_id' => $correlationId,
                'idempotency_key' => $idempotencyKey,
                'request_actor_id' => $actor->id,
                'review_actor_id' => null,
                'review_outcome' => null,
                'review_timestamp' => null,
                'execution_authority' => self::EXECUTION_NOT_IMPLEMENTED,
                'cutover_authority' => self::CUTOVER_NOT_AUTHORIZED,
            ]);
            $pilotAuth->created_by = $actor->id;
            $pilotAuth->updated_by = $actor->id;
            $pilotAuth->save();

            return $pilotAuth->fresh();
        });
    }

    public function review(
        string $pilotAuthorizationId,
        string $reviewOutcome,
        ?User $actor
    ): BankingMigrationPilotAuthorization {
        return DB::transaction(function () use (
            $pilotAuthorizationId,
            $reviewOutcome,
            $actor
        ): BankingMigrationPilotAuthorization {
            $actor = $this->resolveReviewer($actor);
            $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

            $allowedOutcomes = ['REVIEW_ACCEPTED', 'REVIEW_REJECTED'];
            if (!in_array($reviewOutcome, $allowedOutcomes, true)) {
                throw new DomainException('Review outcome must be REVIEW_ACCEPTED or REVIEW_REJECTED.');
            }

            $pilotAuth = BankingMigrationPilotAuthorization::whereKey($pilotAuthorizationId)
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->first();

            if (!$pilotAuth) {
                throw new DomainException('Pilot authorization not found or does not belong to the active property.');
            }

            if ($pilotAuth->status !== BankingMigrationPilotAuthorizationStatusEnum::REQUESTED) {
                throw new DomainException('Only REQUESTED pilot authorizations can be reviewed.');
            }

            if ($pilotAuth->request_actor_id === $actor->id) {
                throw new DomainException('Actor-separation violation: the requester cannot review their own pilot authorization request.');
            }

            $targetIntake = BankingMigrationTargetIntake::whereKey($pilotAuth->target_intake_id)
                ->where('property_id', $propertyId)
                ->first();

            if (!$targetIntake) {
                throw new DomainException('Referenced target intake not found or does not belong to the active property.');
            }

            if ($targetIntake->review_actor_id === $actor->id) {
                throw new DomainException('Actor-separation violation: the target intake reviewer cannot review the related pilot authorization.');
            }

            if ($targetIntake->status !== BankingMigrationTargetIntakeStatusEnum::REVIEW_ACCEPTED) {
                throw new DomainException('Referenced target intake is no longer REVIEW_ACCEPTED.');
            }

            $controlledAccount = ControlledBankAccount::whereKey($targetIntake->controlled_bank_account_id)
                ->where('property_id', $propertyId)
                ->where('is_active', true)
                ->first();

            if (!$controlledAccount) {
                throw new DomainException('Controlled target account is no longer active or does not belong to the active property.');
            }

            $pilotAuth->status = $reviewOutcome === 'REVIEW_ACCEPTED'
                ? BankingMigrationPilotAuthorizationStatusEnum::REVIEW_ACCEPTED
                : BankingMigrationPilotAuthorizationStatusEnum::REVIEW_REJECTED;
            $pilotAuth->review_actor_id = $actor->id;
            $pilotAuth->review_outcome = $reviewOutcome;
            $pilotAuth->review_timestamp = now();
            $pilotAuth->updated_by = $actor->id;
            $pilotAuth->save();

            return $pilotAuth->fresh();
        });
    }

    public function findForProperty(string $pilotAuthId, string $propertyId): ?BankingMigrationPilotAuthorization
    {
        return BankingMigrationPilotAuthorization::whereKey($pilotAuthId)
            ->where('property_id', $propertyId)
            ->first();
    }

    public function listForProperty(string $propertyId): array
    {
        return BankingMigrationPilotAuthorization::with(['targetIntake', 'migrationPlan'])
            ->where('property_id', $propertyId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->all();
    }

    private function resolveRequester(?User $actor): User
    {
        if (!$actor) {
            throw new DomainException('Authenticated actor is required.');
        }

        if (!$actor->can(self::PERMISSION_REQUEST)) {
            throw new DomainException('Actor lacks migration management permission required for pilot authorization request.');
        }

        return $actor;
    }

    private function resolveReviewer(?User $actor): User
    {
        if (!$actor) {
            throw new DomainException('Authenticated actor is required.');
        }

        if (!$actor->can(self::PERMISSION_REVIEW)) {
            throw new DomainException('Actor lacks pilot authorization review permission.');
        }

        return $actor;
    }
}
