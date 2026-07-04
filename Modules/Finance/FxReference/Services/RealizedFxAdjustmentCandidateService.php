<?php

namespace Modules\Finance\FxReference\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityMappingService;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityValidationService;
use Modules\Finance\Payables\Models\ApSettlementAllocation;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Modules\Foundation\User\Models\User;
use Throwable;

class RealizedFxAdjustmentCandidateService
{
    public const PERMISSION = 'finance.fx-adjustment-candidate.create';

    public function __construct(
        private readonly FxAdjustmentEligibilityService $eligibilityService,
        private readonly OperationalIdentityMappingService $mappingService,
        private readonly OperationalIdentityValidationService $identityValidationService
    ) {}

    /**
     * Create a realized FX adjustment JournalCandidate for a fully settled settlement allocation.
     *
     * @param string $allocationId
     * @param User|null $actor
     * @return array|JournalCandidate
     */
    public function create(string $allocationId, ?User $actor): array|JournalCandidate
    {
        return DB::transaction(function () use ($allocationId, $actor) {
            // 1. Resolve allocation & property ID
            $allocation = ApSettlementAllocation::whereKey($allocationId)->lockForUpdate()->firstOrFail();
            $propertyId = $allocation->property_id;

            // 2. Validate actor and permissions
            if (!$actor) {
                throw new AuthorizationException('Realized FX adjustment candidate creation requires an active actor.');
            }
            $freshActor = User::where('id', $actor->id)->where('is_active', true)->first();
            if (!$freshActor) {
                throw new AuthorizationException('Realized FX adjustment candidate creation requires an active actor.');
            }

            $hasPropertyAccess = $freshActor->properties()
                ->where('properties.id', $propertyId)
                ->wherePivot('status', 'active')
                ->exists();
            if (!$hasPropertyAccess) {
                throw new AuthorizationException('Realized FX adjustment candidate creation requires active property access.');
            }

            try {
                $authorized = $freshActor->can(self::PERMISSION);
            } catch (Throwable) {
                throw new AuthorizationException('Realized FX adjustment candidate creation permission is unavailable.');
            }
            if (!$authorized) {
                throw new AuthorizationException('Realized FX adjustment candidate creation permission is required.');
            }

            // 3. Evaluate eligibility
            $eligibility = $this->eligibilityService->evaluate($allocationId, $freshActor);
            if (!$eligibility['eligible']) {
                throw new DomainException('Settlement allocation is not eligible for realized FX candidate creation: ' . $eligibility['reason_code']);
            }

            // 4. Lock remaining evidence models for update
            $paymentExecution = PaymentExecution::whereKey($allocation->payment_execution_id)->lockForUpdate()->firstOrFail();
            $supplierInvoice = SupplierInvoice::whereKey($paymentExecution->supplier_invoice_id)->lockForUpdate()->firstOrFail();
            $apJournal = JournalEntry::with('lines')->whereKey($allocation->ap_journal_entry_id)->lockForUpdate()->firstOrFail();
            $paymentJournal = JournalEntry::with('lines')->whereKey($allocation->payment_journal_entry_id)->lockForUpdate()->firstOrFail();

            // 5. Ensure full, 1-to-1 settlement
            $invoiceTotal = (string)$supplierInvoice->grand_total;
            $allocationAmount = (string)$allocation->allocation_amount;
            if (bccomp($allocationAmount, $invoiceTotal, 2) !== 0) {
                throw new DomainException('Realized FX candidate only supports one-to-one full settlement contexts.');
            }

            // 6. Find AP control line and Cash/Bank line credit amounts
            $apControlMapping = $this->mappingService->resolve($propertyId, OperationalIdentityEnum::AP_CONTROL, $supplierInvoice->invoice_date);
            $apControlLine = $apJournal->lines->first(function ($line) use ($apControlMapping) {
                return $line->account_id === $apControlMapping->account_id &&
                    $line->credit_amount !== null &&
                    bccomp((string)$line->credit_amount, '0.00', 2) > 0;
            });

            if (!$apControlLine) {
                throw new DomainException('AP journal entry is missing credit line on AP Control account.');
            }

            $cashMapping = $this->mappingService->resolve($propertyId, OperationalIdentityEnum::CASH_AND_BANK, $paymentExecution->executed_at);
            $cashLine = $paymentJournal->lines->first(function ($line) use ($cashMapping) {
                return $line->account_id === $cashMapping->account_id &&
                    $line->credit_amount !== null &&
                    bccomp((string)$line->credit_amount, '0.00', 2) > 0;
            });

            if (!$cashLine) {
                throw new DomainException('Payment journal entry is missing credit line on Cash account.');
            }

            $carryingBasis = (string)$apControlLine->credit_amount;
            $settlementBasis = (string)$cashLine->credit_amount;

            // 7. Calculate difference
            $diff = bcsub($carryingBasis, $settlementBasis, 2);
            $comp = bccomp($diff, '0.00', 2);

            if ($comp === 0) {
                return [
                    'status' => 'ZERO_REALIZED_FX_DIFFERENCE',
                    'diff' => '0.00',
                ];
            }

            // 8. Resolve FX mappings and mappings snapshots
            try {
                $fxGainMapping = $this->mappingService->resolve($propertyId, OperationalIdentityEnum::FX_GAIN, $paymentExecution->executed_at);
                $this->identityValidationService->validate(OperationalIdentityEnum::FX_GAIN, $fxGainMapping->account);

                $fxLossMapping = $this->mappingService->resolve($propertyId, OperationalIdentityEnum::FX_LOSS, $paymentExecution->executed_at);
                $this->identityValidationService->validate(OperationalIdentityEnum::FX_LOSS, $fxLossMapping->account);
            } catch (Throwable $e) {
                throw new DomainException('FX operational mappings are invalid: ' . $e->getMessage());
            }

            // 9. Enforce Idempotency (identical replay, conflicting replay, advanced state)
            $existing = JournalCandidate::where('source_type', 'ApSettlementAllocation')
                ->where('source_id', $allocationId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // If candidate state is advanced beyond PENDING_REVIEW, fail closed
                if ($existing->status !== JournalCandidateStatusEnum::PENDING_REVIEW) {
                    throw new DomainException('Existing FX candidate is no longer PENDING_REVIEW.');
                }

                // Check identical replay condition
                $currentMetadataHash = hash('sha256', json_encode([
                    'property_id' => $propertyId,
                    'allocation_id' => $allocationId,
                    'carrying_basis' => $carryingBasis,
                    'settlement_basis' => $settlementBasis,
                    'fx_difference' => $diff,
                ]));

                $existingMetadata = $existing->metadata;
                $existingMetadataHash = hash('sha256', json_encode([
                    'property_id' => $existingMetadata['property_id'] ?? null,
                    'allocation_id' => $existingMetadata['allocation_id'] ?? null,
                    'carrying_basis' => $existingMetadata['carrying_basis'] ?? null,
                    'settlement_basis' => $existingMetadata['settlement_basis'] ?? null,
                    'fx_difference' => $existingMetadata['fx_difference'] ?? null,
                ]));

                if ($currentMetadataHash === $existingMetadataHash) {
                    return $existing->fresh(['lines']);
                }

                // Otherwise, conflicting replay fails controlled
                throw new DomainException('Conflicting candidate replay for same allocation tuple.');
            }

            // 10. Construct metadata evidence snapshots
            $metadata = [
                'contract' => 'realized_fx_adjustment_candidate_v1',
                'property_id' => $propertyId,
                'allocation_id' => $allocationId,
                'carrying_basis' => $carryingBasis,
                'settlement_basis' => $settlementBasis,
                'fx_difference' => $diff,
                'allocation_snapshot' => $eligibility['immutable_evidence_snapshots']['allocation_snapshot'],
                'exchange_rate_evidence_snapshot' => $eligibility['immutable_evidence_snapshots']['exchange_rate_evidence_snapshot'],
                'fx_gain_mapping_snapshot' => $eligibility['immutable_evidence_snapshots']['fx_gain_mapping_snapshot'],
                'fx_loss_mapping_snapshot' => $eligibility['immutable_evidence_snapshots']['fx_loss_mapping_snapshot'],
            ];

            // 11. Create PENDING_REVIEW JournalCandidate
            $candidate = new JournalCandidate([
                'property_id' => $propertyId,
                'source_type' => 'ApSettlementAllocation',
                'source_id' => $allocationId,
                'posting_event' => 'SupplierPaymentRealizedForeignExchange',
                'status' => JournalCandidateStatusEnum::PENDING_REVIEW,
                'candidate_date' => $paymentExecution->executed_at,
                'description' => 'Realized FX adjustment candidate for AP allocation ' . $allocationId,
                'metadata' => $metadata,
            ]);

            $candidate->created_by = $freshActor->id;
            $candidate->updated_by = $freshActor->id;
            $candidate->save();

            // 12. Create lines based on gain vs loss
            if ($comp > 0) {
                $candidate->lines()->create([
                    'operational_identity' => OperationalIdentityEnum::AP_CONTROL,
                    'entry_type' => EntryTypeEnum::DEBIT,
                    'amount' => $diff,
                    'notes' => 'Debit AP Control to clear carrying liability for realized FX gain.',
                ]);

                $candidate->lines()->create([
                    'operational_identity' => OperationalIdentityEnum::FX_GAIN,
                    'entry_type' => EntryTypeEnum::CREDIT,
                    'amount' => $diff,
                    'notes' => 'Credit FX Gain revenue account.',
                ]);
            } else {
                $absoluteDiff = bcsub('0.00', $diff, 2);

                $candidate->lines()->create([
                    'operational_identity' => OperationalIdentityEnum::FX_LOSS,
                    'entry_type' => EntryTypeEnum::DEBIT,
                    'amount' => $absoluteDiff,
                    'notes' => 'Debit FX Loss expense account.',
                ]);

                $candidate->lines()->create([
                    'operational_identity' => OperationalIdentityEnum::AP_CONTROL,
                    'entry_type' => EntryTypeEnum::CREDIT,
                    'amount' => $absoluteDiff,
                    'notes' => 'Credit AP Control to align carrying liability for realized FX loss.',
                ]);
            }

            return $candidate->fresh(['lines']);
        });
    }
}
