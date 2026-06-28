<?php

namespace Modules\Finance\CostControl\Repositories;

use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentScopeSnapshot;

class CostAuthorityEnrollmentRepository
{
    /**
     * Persist a new draft enrollment group and its scope snapshots in one
     * atomic database transaction.
     *
     * Rules:
     *   - $snapshotAttributes must be a non-empty list.
     *   - Each snapshot element must contain all required snapshot fields.
     *   - Does not query InventoryStock, InventoryItem, CostAvcoState,
     *     FinancialPeriod, Outbox, or any GL table.
     *   - Does not write any sequence baseline or item WAC.
     *   - Trigger constraints are the final lifecycle authority.
     *
     * @param  array<string, mixed>   $groupAttributes   Fields for the parent group.
     * @param  array<int, array<string, mixed>>  $snapshotAttributes  Array of per-scope snapshot field arrays.
     */
    public function createDraft(
        array $groupAttributes,
        array $snapshotAttributes
    ): CostAuthorityEnrollmentGroup {
        if (count($snapshotAttributes) === 0) {
            throw new InvalidArgumentException(
                'CostAuthorityEnrollmentRepository::createDraft requires at least one scope snapshot.'
            );
        }

        return DB::transaction(function () use ($groupAttributes, $snapshotAttributes): CostAuthorityEnrollmentGroup {
            $groupId = (string) Str::ulid();

            DB::table('cost_authority_enrollment_groups')->insert([
                'id'          => $groupId,
                'property_id' => $groupAttributes['property_id'],
                'item_id'     => $groupAttributes['item_id'],
                'status'      => CostAuthorityEnrollmentStatusEnum::Draft->value,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            foreach ($snapshotAttributes as $snapshot) {
                DB::table('cost_authority_enrollment_scope_snapshots')->insert([
                    'id'                     => (string) Str::ulid(),
                    'enrollment_group_id'    => $groupId,
                    'location_id'            => $snapshot['location_id'],
                    'valuation_scope'        => $snapshot['valuation_scope'],
                    'opening_quantity'       => $snapshot['opening_quantity'],
                    'opening_carrying_value' => $snapshot['opening_carrying_value'],
                    'currency_code'          => $snapshot['currency_code'],
                    'business_date'          => $snapshot['business_date'],
                    'financial_period_id'    => $snapshot['financial_period_id'],
                    'source_reference'       => $snapshot['source_reference'] ?? null,
                    'evidence_timestamp'     => $snapshot['evidence_timestamp'],
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]);
            }

            $group = CostAuthorityEnrollmentGroup::findOrFail($groupId);
            $group->setRelation('scopeSnapshots', CostAuthorityEnrollmentScopeSnapshot::where('enrollment_group_id', $groupId)->get());

            return $group;
        });
    }

    /**
     * Advance a draft enrollment group to approved.
     *
     * The PostgreSQL trigger enforces the transition rule and requires
     * approved_by and approved_at to be non-null.
     */
    public function approve(
        string $groupId,
        string $approvedBy,
        DateTimeInterface $approvedAt
    ): CostAuthorityEnrollmentGroup {
        $this->requireTransaction(__METHOD__);

        $group = $this->findForUpdate($groupId);

        if ($group->status !== CostAuthorityEnrollmentStatusEnum::Draft) {
            throw new InvalidArgumentException(
                "CostAuthorityEnrollmentRepository::approve requires status='draft'. " .
                "Current status: '{$group->status->value}'."
            );
        }

        $group->status      = CostAuthorityEnrollmentStatusEnum::Approved;
        $group->approved_by = $approvedBy;
        $group->approved_at = $approvedAt;
        $group->save();

        return $group->fresh();
    }

    /**
     * Advance a draft enrollment group to rejected.
     *
     * The PostgreSQL trigger enforces the transition rule and requires
     * rejected_by, rejected_at, and a non-empty rejected_reason.
     */
    public function reject(
        string $groupId,
        string $rejectedBy,
        DateTimeInterface $rejectedAt,
        string $reason
    ): CostAuthorityEnrollmentGroup {
        $this->requireTransaction(__METHOD__);

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'CostAuthorityEnrollmentRepository::reject requires a non-empty reason.'
            );
        }

        $group = $this->findForUpdate($groupId);

        if ($group->status !== CostAuthorityEnrollmentStatusEnum::Draft) {
            throw new InvalidArgumentException(
                "CostAuthorityEnrollmentRepository::reject requires status='draft'. " .
                "Current status: '{$group->status->value}'."
            );
        }

        $group->status          = CostAuthorityEnrollmentStatusEnum::Rejected;
        $group->rejected_by     = $rejectedBy;
        $group->rejected_at     = $rejectedAt;
        $group->rejected_reason = $reason;
        $group->save();

        return $group->fresh();
    }

    /**
     * Supersede an approved (but not yet enrolled) enrollment group.
     *
     * The PostgreSQL trigger enforces the transition rule and requires
     * superseded_by, superseded_at, and a non-empty superseded_reason.
     */
    public function supersedeApproved(
        string $groupId,
        string $supersededBy,
        DateTimeInterface $supersededAt,
        string $reason
    ): CostAuthorityEnrollmentGroup {
        $this->requireTransaction(__METHOD__);

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'CostAuthorityEnrollmentRepository::supersedeApproved requires a non-empty reason.'
            );
        }

        $group = $this->findForUpdate($groupId);

        if ($group->status !== CostAuthorityEnrollmentStatusEnum::Approved) {
            throw new InvalidArgumentException(
                "CostAuthorityEnrollmentRepository::supersedeApproved requires status='approved'. " .
                "Current status: '{$group->status->value}'."
            );
        }

        $group->status             = CostAuthorityEnrollmentStatusEnum::Superseded;
        $group->superseded_by      = $supersededBy;
        $group->superseded_at      = $supersededAt;
        $group->superseded_reason  = $reason;
        $group->save();

        return $group->fresh();
    }

    /**
     * Load and lock all scope snapshots for an enrollment group in deterministic
     * location_id order.
     *
     * SELECT FOR UPDATE does not fire the snapshot lifecycle trigger; only
     * INSERT / UPDATE / DELETE does. Locking is safe even when parent group
     * status is Approved.
     *
     * MUST be called inside an active outer transaction.
     */
    public function findSnapshotsLockedByLocationOrder(string $groupId): \Illuminate\Support\Collection
    {
        $this->requireTransaction(__METHOD__);

        return CostAuthorityEnrollmentScopeSnapshot::where('enrollment_group_id', $groupId)
            ->orderBy('location_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Lock and return the enrollment group row for update.
     * Requires an active outer transaction.
     */
    public function findForUpdate(string $groupId): CostAuthorityEnrollmentGroup
    {
        $this->requireTransaction(__METHOD__);

        $group = CostAuthorityEnrollmentGroup::where('id', $groupId)
            ->lockForUpdate()
            ->first();

        if ($group === null) {
            throw new RuntimeException(
                "CostAuthorityEnrollmentRepository::findForUpdate: no enrollment group found for id='{$groupId}'."
            );
        }

        return $group;
    }

    private function requireTransaction(string $method): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                "{$method} requires an active outer transaction."
            );
        }
    }
}
