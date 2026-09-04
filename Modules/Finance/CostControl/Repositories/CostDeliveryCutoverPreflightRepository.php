<?php

namespace Modules\Finance\CostControl\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Models\CostDeliveryCutoverAttempt;
use Modules\Finance\CostControl\Models\CostDeliveryPilotProperty;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\Inventory\Models\InventoryValuationSequence;
use RuntimeException;

final class CostDeliveryCutoverPreflightRepository
{
    public function findAttemptForUpdate(string $requestId): ?CostDeliveryCutoverAttempt
    {
        $this->requireTransaction();

        return CostDeliveryCutoverAttempt::where('request_id', $requestId)->lockForUpdate()->first();
    }

    public function lockPilotRows(): Collection
    {
        $this->requireTransaction();

        return CostDeliveryPilotProperty::orderBy('pilot_slot')->lockForUpdate()->get();
    }

    public function lockEnrollmentGroup(string $id): ?CostAuthorityEnrollmentGroup
    {
        $this->requireTransaction();

        return CostAuthorityEnrollmentGroup::whereKey($id)->lockForUpdate()->first();
    }

    public function lockSnapshots(string $groupId): Collection
    {
        $this->requireTransaction();

        return DB::table('cost_authority_enrollment_scope_snapshots')
            ->where('enrollment_group_id', $groupId)
            ->orderBy('location_id')
            ->lockForUpdate()
            ->get();
    }

    public function lockPeriod(string $id): ?FinancialPeriod
    {
        $this->requireTransaction();

        return FinancialPeriod::whereKey($id)->lockForUpdate()->first();
    }

    public function lockPriorPeriod(string $propertyId, int $year, int $month): ?FinancialPeriod
    {
        $this->requireTransaction();
        $prior = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('-1 month');

        return FinancialPeriod::where('property_id', $propertyId)
            ->where('period_year', (int) $prior->format('Y'))
            ->where('period_month', (int) $prior->format('n'))
            ->lockForUpdate()->first();
    }

    public function lockBusinessDate(string $propertyId, string $date): ?PropertyBusinessDate
    {
        $this->requireTransaction();

        return PropertyBusinessDate::where('property_id', $propertyId)
            ->whereDate('business_date', $date)->lockForUpdate()->first();
    }

    public function hasSourcesAtOrAfterBoundary(string $propertyId, string $itemId, string $periodId, string $date): bool
    {
        $this->requireTransaction();

        return DB::table('inventory_transactions')->where('property_id', $propertyId)
            ->where('item_id', $itemId)
            ->where(fn ($q) => $q->where('financial_period_id', $periodId)->orWhereDate('business_date', '>=', $date))
            ->exists();
    }

    public function hasInFlightDocuments(string $propertyId, string $itemId): bool
    {
        $this->requireTransaction();
        $checks = [
            ['inventory_receipts', 'inventory_receipt_lines', 'receipt_id', 'item_id', ['draft']],
            ['inventory_issues', 'inventory_issue_lines', 'issue_id', 'item_id', ['draft']],
            ['inventory_adjustments', 'inventory_adjustment_lines', 'adjustment_id', 'item_id', ['draft', 'submitted']],
            ['inventory_transfers', 'inventory_transfer_lines', 'transfer_id', 'item_id', ['draft', 'submitted']],
            ['receiving_documents', 'receiving_lines', 'receiving_document_id', 'inventory_item_id', ['draft', 'submitted']],
        ];
        foreach ($checks as [$headers, $lines, $foreignKey, $itemColumn, $states]) {
            if (DB::table("{$headers} as h")->join("{$lines} as l", "l.{$foreignKey}", '=', 'h.id')
                ->where('h.property_id', $propertyId)->where("l.{$itemColumn}", $itemId)
                ->whereIn('h.status', $states)->whereNull('h.deleted_at')->lockForUpdate()->exists()) {
                return true;
            }
        }

        return false;
    }

    public function hasTerminalPostingEvidenceGap(string $propertyId, string $itemId): bool
    {
        $checks = [
            ['inventory_receipts', 'inventory_receipt_lines', 'receipt_id', 'item_id', 'posted', 'inventory_receipt', 'inventory_receipt_line', ['purchase_receipt']],
            ['inventory_issues', 'inventory_issue_lines', 'issue_id', 'item_id', 'posted', 'inventory_issue', 'inventory_issue_line', ['issue']],
            ['inventory_adjustments', 'inventory_adjustment_lines', 'adjustment_id', 'item_id', 'approved', 'inventory_adjustment', 'inventory_adjustment_line', ['adjustment_in', 'adjustment_out']],
            ['inventory_transfers', 'inventory_transfer_lines', 'transfer_id', 'item_id', 'completed', 'inventory_transfer', 'inventory_transfer_line', ['transfer_out', 'transfer_in']],
            ['receiving_documents', 'receiving_lines', 'receiving_document_id', 'inventory_item_id', 'approved', 'receiving_document', 'receiving_line', ['purchase_receipt']],
        ];
        foreach ($checks as [$headers, $lines, $foreignKey, $itemColumn, $status, $docType, $lineType, $roles]) {
            $rows = DB::table("{$headers} as h")->join("{$lines} as l", "l.{$foreignKey}", '=', 'h.id')
                ->leftJoin('inventory_transactions as t', function ($join) use ($docType, $lineType) {
                    $join->on('t.source_document_id', '=', 'h.id')->on('t.source_line_id', '=', 'l.id')
                        ->where('t.source_document_type', $docType)->where('t.source_line_type', $lineType);
                })->where('h.property_id', $propertyId)->where("l.{$itemColumn}", $itemId)
                ->where('h.status', $status)->whereNull('h.deleted_at')
                ->selectRaw("l.id, COUNT(t.id) AS source_count, STRING_AGG(t.movement_role, ',' ORDER BY t.movement_role) AS roles")
                ->groupBy('l.id')->get();
            $expectedCount = $docType === 'inventory_adjustment' ? 1 : count($roles);
            sort($roles, SORT_STRING);
            foreach ($rows as $row) {
                $actualRoles = $row->roles === null ? [] : explode(',', $row->roles);
                sort($actualRoles, SORT_STRING);
                $validRoles = $docType === 'inventory_adjustment'
                    ? count($actualRoles) === 1 && in_array($actualRoles[0], $roles, true)
                    : $actualRoles === $roles;
                if ((int) $row->source_count !== $expectedCount || ! $validRoles) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasUnclassifiedHistoricalEvidence(string $propertyId, string $itemId, string $boundary): bool
    {
        $this->requireTransaction();
        $row = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                  FROM inventory_transactions t
                 WHERE t.property_id = ?
                   AND t.item_id = ?
                   AND t.business_date < ?::date
                   AND (
                        t.cost_delivery_mode = 'DEFERRED'
                        OR (SELECT COUNT(*) FROM outbox_messages o
                             WHERE o.source_inventory_transaction_id = t.id) <> 1
                        OR NOT EXISTS (
                            SELECT 1 FROM outbox_messages o
                             WHERE o.source_inventory_transaction_id = t.id
                               AND o.topic = 'inventory.transaction.posted'
                               AND o.idempotency_key = 'inventory_transaction:' || t.id || ':cost_ledger'
                               AND o.payload::jsonb = jsonb_build_object('transactionId', t.id)
                        )
                        OR (SELECT COUNT(*) FROM cost_delivery_outbox_dispositions d
                             WHERE d.source_inventory_transaction_id = t.id) <> 1
                        OR NOT EXISTS (
                            SELECT 1
                              FROM cost_delivery_outbox_dispositions d
                              JOIN outbox_messages o ON o.id = d.outbox_message_id
                             WHERE d.source_inventory_transaction_id = t.id
                               AND o.source_inventory_transaction_id = t.id
                               AND d.property_id = t.property_id
                               AND d.location_id = t.location_id
                               AND d.item_id = t.item_id
                               AND d.valuation_scope = t.valuation_scope
                               AND d.valuation_sequence = t.valuation_sequence
                               AND d.cost_delivery_ownership_id IS NOT DISTINCT FROM t.cost_delivery_ownership_id
                               AND d.cost_delivery_ownership_version IS NOT DISTINCT FROM t.cost_delivery_ownership_version
                               AND d.cost_delivery_cutover_id IS NOT DISTINCT FROM t.cost_delivery_cutover_id
                               AND (
                                    (d.classification = 'SYNCHRONOUSLY_SATISFIED_HISTORY'
                                     AND d.processing_state = 'HISTORICAL_EXCLUDED'
                                     AND d.equivalent_cost_ledger_entry_id IS NOT NULL
                                     AND t.cost_delivery_mode IS DISTINCT FROM 'DEFERRED'
                                     AND EXISTS (
                                         SELECT 1 FROM cost_ledger_entries l
                                          WHERE l.id = d.equivalent_cost_ledger_entry_id
                                            AND l.property_id = t.property_id
                                            AND l.source_inventory_transaction_id = t.id
                                            AND l.entry_sequence = t.valuation_sequence
                                     ))
                                    OR
                                    (d.classification = 'UNENROLLED_OR_NON_COSTCONTROL_ELIGIBLE_HISTORY'
                                     AND d.processing_state = 'HISTORICAL_EXCLUDED'
                                     AND d.equivalent_cost_ledger_entry_id IS NULL
                                     AND t.cost_delivery_mode IS NULL
                                     AND t.cost_delivery_ownership_id IS NULL
                                     AND t.cost_delivery_ownership_version IS NULL
                                     AND t.cost_delivery_cutover_id IS NULL)
                               )
                        )
                   )
            ) AS evidence_gap
            SQL, [$propertyId, $itemId, $boundary]);

        return (bool) $row->evidence_gap;
    }

    public function hasUnresolvedDisposition(string $propertyId, string $itemId): bool
    {
        return DB::table('cost_delivery_outbox_dispositions')->where('property_id', $propertyId)
            ->where('item_id', $itemId)->whereIn('processing_state', ['PENDING', 'FAILED', 'BLOCKED_SEQUENCE'])
            ->lockForUpdate()->exists();
    }

    public function schemaControlsInstalled(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }
        $constraints = DB::table('pg_constraint')->whereIn('conname', [
            'uk_cost_ledger_source_inventory_transaction',
            'chk_inv_tx_cost_delivery_stamp',
            'chk_inventory_transactions_no_self_reversal',
        ])->pluck('conname')->all();
        $reversalIndex = DB::table('pg_indexes')->where('indexname', 'idx_inventory_transactions_reversal_limit')->exists();

        return count(array_unique($constraints)) === 3 && $reversalIndex;
    }

    public function lockScopeSequenceState(string $propertyId, string $locationId, string $itemId): array
    {
        $this->requireTransaction();
        $allocator = InventoryValuationSequence::where('property_id', $propertyId)->where('location_id', $locationId)
            ->where('item_id', $itemId)->lockForUpdate()->first();
        $positiveSource = DB::table('inventory_transactions')->where('property_id', $propertyId)
            ->where('location_id', $locationId)->where('item_id', $itemId)
            ->where('valuation_sequence', '>', 0)->exists();

        return [$allocator, $positiveSource];
    }

    public function lockScopeAvcoState(string $propertyId, string $locationId, string $itemId): ?CostAvcoState
    {
        $this->requireTransaction();

        return CostAvcoState::where('property_id', $propertyId)->where('location_id', $locationId)
            ->where('item_id', $itemId)->lockForUpdate()->first();
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Cost delivery cutover preflight requires an active outer transaction.');
        }
    }
}
