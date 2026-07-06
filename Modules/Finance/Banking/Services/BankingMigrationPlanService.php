<?php

namespace Modules\Finance\Banking\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankingMigrationPlanStatusEnum;
use Modules\Finance\Banking\Models\BankingMigrationPlan;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Throwable;

class BankingMigrationPlanService
{
    public const PERMISSION_VIEW = 'finance.banking.migration.view';
    public const PERMISSION_MANAGE = 'finance.banking.migration.manage';
    public const CONTRACT = 'banking_migration_plan_v1';
    public const SOURCE_DOMAIN = 'legacy_banking';
    public const TARGET_DOMAIN = 'controlled_banking';
    public const CUTOVER_NOT_AUTHORIZED = 'CUTOVER_NOT_AUTHORIZED';
    public const EXECUTION_UNAVAILABLE = 'UNAVAILABLE';

    public function createPlan(
        string $requestIdentity,
        ?User $actor
    ): BankingMigrationPlan {
        return DB::transaction(function () use ($requestIdentity, $actor): BankingMigrationPlan {
            $actor = $this->resolveAuthorizedActor($actor);

            $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

            $requestIdentity = $this->requiredText($requestIdentity, 'Request identity is required.');

            $correlationId = (string) Str::ulid();

            $idempotencyKey = $this->buildIdempotencyKey($propertyId, $requestIdentity, $actor->id);

            $existing = BankingMigrationPlan::where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingPlanMatches($existing, $propertyId, $actor->id);

                return $existing->fresh();
            }

            $plan = new BankingMigrationPlan([
                'property_id' => $propertyId,
                'source_domain' => self::SOURCE_DOMAIN,
                'target_domain' => self::TARGET_DOMAIN,
                'status' => BankingMigrationPlanStatusEnum::DRAFT,
                'correlation_id' => $correlationId,
                'idempotency_key' => $idempotencyKey,
                'dry_run_metadata' => null,
                'execution_authority' => self::EXECUTION_UNAVAILABLE,
                'cutover_authority' => self::CUTOVER_NOT_AUTHORIZED,
                'created_actor_id' => $actor->id,
            ]);
            $plan->created_by = $actor->id;
            $plan->updated_by = $actor->id;
            $plan->save();

            return $plan->fresh();
        });
    }

    public function findForProperty(string $planId, string $propertyId): ?BankingMigrationPlan
    {
        return BankingMigrationPlan::whereKey($planId)
            ->where('property_id', $propertyId)
            ->first();
    }

    public function findForPropertyOrFail(string $planId, string $propertyId): BankingMigrationPlan
    {
        return BankingMigrationPlan::whereKey($planId)
            ->where('property_id', $propertyId)
            ->firstOrFail();
    }

    public function listForProperty(string $propertyId): array
    {
        return BankingMigrationPlan::where('property_id', $propertyId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->all();
    }

    public function requestDryRun(string $planId, ?User $actor): BankingMigrationPlan
    {
        return DB::transaction(function () use ($planId, $actor): BankingMigrationPlan {
            $actor = $this->resolveAuthorizedActor($actor);

            $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

            $plan = $this->findForPropertyOrFail($planId, $propertyId);

            if ($plan->status !== BankingMigrationPlanStatusEnum::DRAFT) {
                throw new DomainException('Only DRAFT plans can request a dry run.');
            }

            $plan->status = BankingMigrationPlanStatusEnum::DRY_RUN_REQUESTED;
            $plan->updated_by = $actor->id;
            $plan->save();

            return $plan->fresh();
        });
    }

    private function buildIdempotencyKey(string $propertyId, string $requestIdentity, string $actorId): string
    {
        return hash('sha256', implode('|', [
            self::CONTRACT,
            $propertyId,
            $requestIdentity,
            $actorId,
        ]));
    }

    private function resolveAuthorizedActor(?User $actor): User
    {
        if (!$actor) {
            throw new DomainException('Authenticated actor is required.');
        }

        if (!$actor->can(self::PERMISSION_MANAGE)) {
            throw new DomainException('Actor lacks migration plan management permission.');
        }

        return $actor;
    }

    private function requiredText(string $value, string $message): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new DomainException($message);
        }

        return $value;
    }

    private function assertExistingPlanMatches(
        BankingMigrationPlan $existing,
        string $propertyId,
        string $actorId
    ): void {
        if ($existing->property_id !== $propertyId) {
            throw new DomainException('Existing plan property mismatch.');
        }

        if ($existing->created_actor_id !== $actorId) {
            throw new DomainException('Existing plan actor mismatch.');
        }

        if ($existing->source_domain !== self::SOURCE_DOMAIN) {
            throw new DomainException('Existing plan source domain mismatch.');
        }

        if ($existing->target_domain !== self::TARGET_DOMAIN) {
            throw new DomainException('Existing plan target domain mismatch.');
        }

        if ($existing->cutover_authority !== self::CUTOVER_NOT_AUTHORIZED) {
            throw new DomainException('Existing plan cutover authority conflict.');
        }
    }
}
