<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use Modules\Operations\Inventory\Exceptions\InventoryReversalCandidateRejectedException;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;

class InventoryReversalCandidateGuard
{
    private InventoryTransactionRepository $repository;

    public function __construct(InventoryTransactionRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Lock and validate the candidate transaction for Reversal v1 eligibility.
     * Must be executed within an active database transaction.
     *
     * @throws InventoryReversalCandidateRejectedException
     */
    public function guard(string $candidateId): InventoryTransaction
    {
        if (DB::transactionLevel() < 1) {
            throw new InventoryReversalCandidateRejectedException(
                'missing_outer_transaction',
                'No active outer database transaction.'
            );
        }

        $candidate = $this->repository->findAndLock($candidateId);

        if (!$candidate) {
            throw new InventoryReversalCandidateRejectedException(
                'candidate_not_found',
                'Candidate original transaction not found.'
            );
        }

        if ($candidate->reverses_inventory_transaction_id !== null) {
            throw new InventoryReversalCandidateRejectedException(
                'candidate_is_already_a_reversal',
                'Candidate transaction is itself already a reversal.'
            );
        }

        if ($this->repository->hasReversal($candidateId)) {
            throw new InventoryReversalCandidateRejectedException(
                'candidate_already_has_reversal',
                'An existing reversal already references the candidate.'
            );
        }

        if (
            $candidate->transaction_type !== TransactionTypeEnum::PurchaseReceipt &&
            $candidate->transaction_type !== TransactionTypeEnum::Issue
        ) {
            throw new InventoryReversalCandidateRejectedException(
                'candidate_type_not_eligible',
                'Candidate transaction type is not eligible for Reversal v1.'
            );
        }

        if (
            empty($candidate->property_id) ||
            empty($candidate->location_id) ||
            empty($candidate->item_id) ||
            empty($candidate->valuation_scope) ||
            $candidate->valuation_sequence === null
        ) {
            throw new InventoryReversalCandidateRejectedException(
                'candidate_missing_controlled_evidence',
                'Required controlled valuation evidence is absent.'
            );
        }

        return $candidate;
    }
}
