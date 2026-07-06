<?php

namespace Modules\Finance\Banking\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankingMigrationExceptionCodeEnum;
use Modules\Finance\Banking\Enums\BankingMigrationExceptionSeverityEnum;
use Modules\Finance\Banking\Enums\BankingMigrationInventoryStatusEnum;
use Modules\Finance\Banking\Enums\BankingMigrationPlanStatusEnum;
use Modules\Finance\Banking\Models\BankingMigrationExceptionQuarantine;
use Modules\Finance\Banking\Models\BankingMigrationManifestEntry;
use Modules\Finance\Banking\Models\BankingMigrationPlan;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Throwable;

class BankingMigrationDryRunService
{
    public const DRY_RUN_CONTRACT = 'banking_migration_dry_run_v1';

    private const ALLOWED_SOURCE_MODELS = [
        'BankAccount',
        'BankStatementLine',
        'ReconciliationMatch',
        'ReconciliationSession',
    ];

    public function executeDryRun(string $planId, ?User $actor): array
    {
        return DB::transaction(function () use ($planId, $actor): array {
            $actor = $this->resolveAuthorizedActor($actor);

            $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

            $plan = BankingMigrationPlan::whereKey($planId)
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->first();

            if (!$plan) {
                throw new DomainException('Migration plan not found for the current property.');
            }

            $dryRunVersion = $this->buildDryRunVersion($plan->id);

            $existingEntries = BankingMigrationManifestEntry::where('migration_plan_id', $plan->id)
                ->where('dry_run_version', $dryRunVersion)
                ->exists();

            if ($existingEntries) {
                return $this->buildExistingSummary($plan, $dryRunVersion);
            }

            if ($plan->status !== BankingMigrationPlanStatusEnum::DRY_RUN_REQUESTED) {
                throw new DomainException('Only plans in DRY_RUN_REQUESTED status can execute a dry run.');
            }

            $manifestEntries = [];
            $quarantineRecords = [];
            $manifestCount = 0;
            $quarantineCount = 0;
            $inventoriedCount = 0;
            $excludedCount = 0;
            $blockedCount = 0;
            $quarantinedCount = 0;

            foreach (self::ALLOWED_SOURCE_MODELS as $sourceModel) {
                $sourceTable = $this->modelToTable($sourceModel);

                $rows = DB::table($sourceTable)
                    ->where('property_id', $propertyId)
                    ->orderBy('created_at')
                    ->get();

                $seenSourceIds = [];

                foreach ($rows as $row) {
                    $sourceUid = $row->id ?? null;

                    if (!$sourceUid) {
                        $this->recordException(
                            $plan, null, BankingMigrationExceptionCodeEnum::SOURCE_IDENTITY_UNAVAILABLE,
                            BankingMigrationExceptionSeverityEnum::BLOCKER,
                            BankingMigrationPlanService::SOURCE_DOMAIN, $sourceModel, null, $propertyId,
                            $plan->correlation_id
                        );
                        $quarantineCount++;
                        continue;
                    }

                    if (empty($row->property_id)) {
                        $this->recordException(
                            $plan, null, BankingMigrationExceptionCodeEnum::SOURCE_PROPERTY_SCOPE_UNAVAILABLE,
                            BankingMigrationExceptionSeverityEnum::BLOCKER,
                            BankingMigrationPlanService::SOURCE_DOMAIN, $sourceModel, $sourceUid, $propertyId,
                            $plan->correlation_id
                        );
                        $quarantineCount++;
                        continue;
                    }

                    $duplicateKey = $sourceModel . '|' . $sourceUid;
                    if (isset($seenSourceIds[$duplicateKey])) {
                        $status = BankingMigrationInventoryStatusEnum::QUARANTINED;
                        $quarantinedCount++;

                        $entry = $this->createManifestEntry(
                            $plan, $sourceModel, $sourceUid, $propertyId, $dryRunVersion,
                            $status
                        );
                        $manifestEntries[] = $entry;
                        $manifestCount++;

                        $this->recordException(
                            $plan, $entry, BankingMigrationExceptionCodeEnum::DUPLICATE_SOURCE_IDENTITY,
                            BankingMigrationExceptionSeverityEnum::WARNING,
                            BankingMigrationPlanService::SOURCE_DOMAIN, $sourceModel, $sourceUid, $propertyId,
                            $plan->correlation_id
                        );
                        $quarantineCount++;
                        continue;
                    }

                    $seenSourceIds[$duplicateKey] = true;

                    $createdAt = $row->created_at ?? null;
                    $updatedAt = $row->updated_at ?? null;

                    if ($createdAt === null && $updatedAt === null) {
                        $this->recordException(
                            $plan, null, BankingMigrationExceptionCodeEnum::SOURCE_SNAPSHOT_UNAVAILABLE,
                            BankingMigrationExceptionSeverityEnum::WARNING,
                            BankingMigrationPlanService::SOURCE_DOMAIN, $sourceModel, $sourceUid, $propertyId,
                            $plan->correlation_id
                        );
                        $quarantineCount++;
                        $status = BankingMigrationInventoryStatusEnum::QUARANTINED;
                        $quarantinedCount++;
                    } else {
                        $status = BankingMigrationInventoryStatusEnum::INVENTORIED;
                        $inventoriedCount++;
                    }

                    $entry = $this->createManifestEntry(
                        $plan, $sourceModel, $sourceUid, $propertyId, $dryRunVersion,
                        $status, $createdAt, $updatedAt
                    );
                    $manifestEntries[] = $entry;
                    $manifestCount++;
                }
            }

            $blocked = $quarantinedCount > 0;

            $plan->status = $blocked
                ? BankingMigrationPlanStatusEnum::BLOCKED
                : BankingMigrationPlanStatusEnum::DRY_RUN_COMPLETED;

            $plan->dry_run_metadata = [
                'completed_at' => now()->toIso8601String(),
                'dry_run_version' => $dryRunVersion,
                'manifest_count' => $manifestCount,
                'quarantine_count' => $quarantineCount,
                'inventoried_count' => $inventoriedCount,
                'excluded_count' => $excludedCount,
                'blocked_count' => $blockedCount,
                'quarantined_count' => $quarantinedCount,
            ];
            $plan->updated_by = $actor->id;
            $plan->save();

            return [
                'plan_id' => $plan->id,
                'plan_status' => $plan->status->value,
                'dry_run_version' => $dryRunVersion,
                'cutover_authority' => $plan->cutover_authority,
                'manifest_count' => $manifestCount,
                'quarantine_count' => $quarantineCount,
                'inventoried_count' => $inventoriedCount,
                'quarantined_count' => $quarantinedCount,
            ];
        });
    }

    public function getDryRunSummary(string $planId, string $propertyId): ?array
    {
        $plan = BankingMigrationPlan::whereKey($planId)
            ->where('property_id', $propertyId)
            ->first();

        if (!$plan) {
            return null;
        }

        $metadata = $plan->dry_run_metadata ?? [];

        return [
            'plan_id' => $plan->id,
            'plan_status' => $plan->status?->value,
            'cutover_authority' => $plan->cutover_authority,
            'execution_authority' => $plan->execution_authority,
            'dry_run_completed_at' => $metadata['completed_at'] ?? null,
            'dry_run_version' => $metadata['dry_run_version'] ?? null,
            'manifest_count' => $metadata['manifest_count'] ?? 0,
            'quarantine_count' => $metadata['quarantine_count'] ?? 0,
            'inventoried_count' => $metadata['inventoried_count'] ?? 0,
            'quarantined_count' => $metadata['quarantined_count'] ?? 0,
        ];
    }

    private function resolveAuthorizedActor(?User $actor): User
    {
        if (!$actor) {
            throw new DomainException('Authenticated actor is required.');
        }

        if (!$actor->can(BankingMigrationPlanService::PERMISSION_MANAGE)) {
            throw new DomainException('Actor lacks migration plan management permission.');
        }

        return $actor;
    }

    private function buildDryRunVersion(string $planId): string
    {
        return hash('sha256', implode('|', [
            self::DRY_RUN_CONTRACT,
            $planId,
            (string) now()->getTimestamp(),
        ]));
    }

    private function buildSourceIdentityHash(string $sourceModel, string $sourceUid, string $sourcePropertyId): string
    {
        return hash('sha256', implode('|', [
            BankingMigrationPlanService::SOURCE_DOMAIN,
            $sourceModel,
            $sourceUid,
            $sourcePropertyId,
        ]));
    }

    private function buildSourceSnapshotHash(
        string $sourceModel,
        string $sourceUid,
        string $sourcePropertyId,
        ?string $createdAt,
        ?string $updatedAt
    ): string {
        return hash('sha256', implode('|', [
            $sourceModel,
            $sourceUid,
            $sourcePropertyId,
            $createdAt ?? 'null',
            $updatedAt ?? 'null',
        ]));
    }

    private function createManifestEntry(
        BankingMigrationPlan $plan,
        string $sourceModel,
        string $sourceUid,
        string $sourcePropertyId,
        string $dryRunVersion,
        BankingMigrationInventoryStatusEnum $status,
        ?string $createdAt = null,
        ?string $updatedAt = null
    ): BankingMigrationManifestEntry {
        $existing = BankingMigrationManifestEntry::where('migration_plan_id', $plan->id)
            ->where('source_domain', BankingMigrationPlanService::SOURCE_DOMAIN)
            ->where('source_model', $sourceModel)
            ->where('source_ulid', $sourceUid)
            ->where('dry_run_version', $dryRunVersion)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        $entry = new BankingMigrationManifestEntry([
            'migration_plan_id' => $plan->id,
            'source_domain' => BankingMigrationPlanService::SOURCE_DOMAIN,
            'source_model' => $sourceModel,
            'source_ulid' => $sourceUid,
            'source_property_id' => $sourcePropertyId,
            'source_identity_hash' => $this->buildSourceIdentityHash($sourceModel, $sourceUid, $sourcePropertyId),
            'source_snapshot_hash' => $this->buildSourceSnapshotHash(
                $sourceModel, $sourceUid, $sourcePropertyId, $createdAt, $updatedAt
            ),
            'dry_run_version' => $dryRunVersion,
            'inventory_status' => $status,
        ]);
        $entry->save();

        return $entry;
    }

    private function recordException(
        BankingMigrationPlan $plan,
        ?BankingMigrationManifestEntry $manifestEntry,
        BankingMigrationExceptionCodeEnum $code,
        BankingMigrationExceptionSeverityEnum $severity,
        string $sourceDomain,
        ?string $sourceModel,
        ?string $sourceUid,
        ?string $sourcePropertyId,
        string $correlationId
    ): void {
        $existing = BankingMigrationExceptionQuarantine::where('migration_plan_id', $plan->id)
            ->where('exception_code', $code->value)
            ->where('source_domain', $sourceDomain)
            ->where('source_model', $sourceModel ?? '')
            ->where('source_ulid', $sourceUid ?? '')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return;
        }

        $quarantine = new BankingMigrationExceptionQuarantine([
            'migration_plan_id' => $plan->id,
            'manifest_entry_id' => $manifestEntry?->id,
            'exception_code' => $code,
            'severity' => $severity,
            'source_domain' => $sourceDomain,
            'source_model' => $sourceModel,
            'source_ulid' => $sourceUid,
            'source_property_id' => $sourcePropertyId,
            'correlation_id' => $correlationId,
            'is_resolved' => false,
        ]);
        $quarantine->save();
    }

    private function modelToTable(string $model): string
    {
        return match ($model) {
            'BankAccount' => 'bank_accounts',
            'BankStatementLine' => 'bank_statement_lines',
            'ReconciliationMatch' => 'reconciliation_matches',
            'ReconciliationSession' => 'reconciliation_sessions',
            default => throw new DomainException("Unsupported legacy source type: {$model}"),
        };
    }

    private function buildExistingSummary(BankingMigrationPlan $plan, string $dryRunVersion): array
    {
        $metadata = $plan->dry_run_metadata ?? [];

        return [
            'plan_id' => $plan->id,
            'plan_status' => $plan->status->value,
            'dry_run_version' => $dryRunVersion,
            'cutover_authority' => $plan->cutover_authority,
            'manifest_count' => $metadata['manifest_count'] ?? 0,
            'quarantine_count' => $metadata['quarantine_count'] ?? 0,
            'inventoried_count' => $metadata['inventoried_count'] ?? 0,
            'quarantined_count' => $metadata['quarantined_count'] ?? 0,
        ];
    }
}
