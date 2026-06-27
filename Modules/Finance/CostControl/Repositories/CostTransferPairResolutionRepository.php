<?php

namespace Modules\Finance\CostControl\Repositories;

use InvalidArgumentException;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Models\CostTransferPairResolution;

class CostTransferPairResolutionRepository
{
    /**
     * Bootstrap the pair-resolution row for the given canonical pair key if it
     * does not exist, then lock and return it.
     *
     * Uses the PostgreSQL-safe INSERT OR IGNORE + SELECT FOR UPDATE pattern.
     * Requires an active outer transaction.
     *
     * $identity must contain all required immutable identity fields:
     *   property_id, source_document_id, source_line_id,
     *   source_inventory_transaction_id, destination_inventory_transaction_id,
     *   source_valuation_scope, destination_valuation_scope,
     *   source_valuation_sequence, destination_valuation_sequence
     */
    public function bootstrapAndLock(array $identity): CostTransferPairResolution
    {
        $this->requireTransaction(__METHOD__);

        $requiredKeys = [
            'property_id',
            'source_document_id',
            'source_line_id',
            'source_inventory_transaction_id',
            'destination_inventory_transaction_id',
            'source_valuation_scope',
            'destination_valuation_scope',
            'source_valuation_sequence',
            'destination_valuation_sequence',
        ];

        foreach ($requiredKeys as $key) {
            if (!isset($identity[$key]) || (string) $identity[$key] === '') {
                throw new InvalidArgumentException(
                    "CostTransferPairResolutionRepository::bootstrapAndLock requires non-empty '{$key}'."
                );
            }
        }

        DB::table('cost_transfer_pair_resolutions')->insertOrIgnore([
            'id'                                   => (string) Str::ulid(),
            'property_id'                          => $identity['property_id'],
            'source_document_id'                   => $identity['source_document_id'],
            'source_line_id'                       => $identity['source_line_id'],
            'source_inventory_transaction_id'      => $identity['source_inventory_transaction_id'],
            'destination_inventory_transaction_id' => $identity['destination_inventory_transaction_id'],
            'source_valuation_scope'               => $identity['source_valuation_scope'],
            'destination_valuation_scope'          => $identity['destination_valuation_scope'],
            'source_valuation_sequence'            => $identity['source_valuation_sequence'],
            'destination_valuation_sequence'       => $identity['destination_valuation_sequence'],
            'lifecycle_status'                     => 'pending',
            'created_at'                           => now(),
            'updated_at'                           => now(),
        ]);

        $row = CostTransferPairResolution::where('property_id', $identity['property_id'])
            ->where('source_document_id', $identity['source_document_id'])
            ->where('source_line_id', $identity['source_line_id'])
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new RuntimeException(
                'CostTransferPairResolutionRepository: failed to resolve pair-resolution row after bootstrap.'
            );
        }

        return $row;
    }

    /**
     * Find and lock an existing pair-resolution row by canonical pair key.
     * Returns null if no row exists for this pair.
     * Requires an active outer transaction.
     */
    public function findAndLock(
        string $propertyId,
        string $sourceDocumentId,
        string $sourceLineId
    ): ?CostTransferPairResolution {
        $this->requireTransaction(__METHOD__);

        return CostTransferPairResolution::where('property_id', $propertyId)
            ->where('source_document_id', $sourceDocumentId)
            ->where('source_line_id', $sourceLineId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Freeze the source AVCO/WAUC into the pair-resolution row and advance
     * lifecycle_status from pending → frozen.
     *
     * Allowed only from pending.
     * Requires an active outer transaction.
     */
    public function freezeSourceUnitCost(
        CostTransferPairResolution $resolution,
        string $frozenUnitCost
    ): void {
        $this->requireTransaction(__METHOD__);

        if ($resolution->lifecycle_status !== 'pending') {
            throw new InvalidArgumentException(
                "CostTransferPairResolutionRepository::freezeSourceUnitCost requires lifecycle_status='pending'. " .
                "Current status: '{$resolution->lifecycle_status}'."
            );
        }

        if (!is_numeric($frozenUnitCost) || bccomp($frozenUnitCost, '0', 4) < 0) {
            throw new InvalidArgumentException(
                "CostTransferPairResolutionRepository::freezeSourceUnitCost requires a non-negative numeric value. " .
                "Received: '{$frozenUnitCost}'."
            );
        }

        $resolution->frozen_source_unit_cost = $frozenUnitCost;
        $resolution->lifecycle_status        = 'frozen';
        $resolution->save();
    }

    /**
     * Record a blocking reason code on the pair-resolution row.
     * Allowed only from pending or frozen. Does not advance lifecycle_status.
     * Requires an active outer transaction.
     */
    public function recordBlockingReason(
        CostTransferPairResolution $resolution,
        string $reasonCode
    ): void {
        $this->requireTransaction(__METHOD__);

        if (!in_array($resolution->lifecycle_status, ['pending', 'frozen'], true)) {
            throw new InvalidArgumentException(
                "CostTransferPairResolutionRepository::recordBlockingReason is only allowed from 'pending' or 'frozen'. " .
                "Current status: '{$resolution->lifecycle_status}'."
            );
        }

        if (trim($reasonCode) === '') {
            throw new InvalidArgumentException(
                'CostTransferPairResolutionRepository::recordBlockingReason requires a non-empty reason code.'
            );
        }

        $resolution->blocking_reason_code = $reasonCode;
        $resolution->save();
    }

    /**
     * Advance lifecycle_status from frozen → applied.
     * Requires an active outer transaction.
     */
    public function markApplied(CostTransferPairResolution $resolution): void
    {
        $this->requireTransaction(__METHOD__);

        if ($resolution->lifecycle_status !== 'frozen') {
            throw new InvalidArgumentException(
                "CostTransferPairResolutionRepository::markApplied requires lifecycle_status='frozen'. " .
                "Current status: '{$resolution->lifecycle_status}'."
            );
        }

        $resolution->lifecycle_status = 'applied';
        $resolution->save();
    }

    /**
     * Advance lifecycle_status from applied → delivered.
     * Requires an active outer transaction.
     */
    public function markDelivered(CostTransferPairResolution $resolution): void
    {
        $this->requireTransaction(__METHOD__);

        if ($resolution->lifecycle_status !== 'applied') {
            throw new InvalidArgumentException(
                "CostTransferPairResolutionRepository::markDelivered requires lifecycle_status='applied'. " .
                "Current status: '{$resolution->lifecycle_status}'."
            );
        }

        $resolution->lifecycle_status = 'delivered';
        $resolution->save();
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
