<?php

namespace Modules\Finance\FxReference\Services;

use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\FxReference\Enums\ExchangeRateEvidenceStatusEnum;
use Modules\Finance\FxReference\Models\ExchangeRateEvidence;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityMappingService;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityValidationService;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityMappingNotFoundException;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityValidationException;
use Modules\Finance\Payables\Models\ApSettlementAllocation;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Modules\Foundation\User\Models\User;
use Throwable;

class FxAdjustmentEligibilityService
{
    public const PERMISSION = 'finance.payables.ap-settlement.allocate';

    public function __construct(
        private readonly OperationalIdentityMappingService $mappingService,
        private readonly OperationalIdentityValidationService $identityValidationService
    ) {}

    /**
     * Evaluate the eligibility of a posted settlement allocation for a future FX adjustment decision.
     *
     * @param string $allocationId
     * @param User|null $actor
     * @return array
     */
    public function evaluate(string $allocationId, ?User $actor): array
    {
        try {
            // 1. Actor check
            if (!$actor) {
                return [
                    'eligible' => false,
                    'reason_code' => 'INACTIVE_ACTOR',
                ];
            }

            $freshActor = User::where('id', $actor->id)
                ->where('is_active', true)
                ->first();

            if (!$freshActor) {
                return [
                    'eligible' => false,
                    'reason_code' => 'INACTIVE_ACTOR',
                ];
            }

            // 2. Allocation lookup
            $allocation = ApSettlementAllocation::whereKey($allocationId)->first();
            if (!$allocation) {
                return [
                    'eligible' => false,
                    'reason_code' => 'MISSING_PROVENANCE',
                ];
            }

            $propertyId = $allocation->property_id;

            // 3. Property access & permission checks
            $hasPropertyAccess = $freshActor->properties()
                ->where('properties.id', $propertyId)
                ->wherePivot('status', 'active')
                ->exists();

            if (!$hasPropertyAccess) {
                return [
                    'eligible' => false,
                    'reason_code' => 'PROPERTY_ACCESS_DENIED',
                ];
            }

            try {
                $authorized = $freshActor->can(self::PERMISSION);
            } catch (Throwable) {
                return [
                    'eligible' => false,
                    'reason_code' => 'UNAUTHORIZED_ACTOR',
                ];
            }

            if (!$authorized) {
                return [
                    'eligible' => false,
                    'reason_code' => 'UNAUTHORIZED_ACTOR',
                ];
            }

            // 4. Linked provenance checks
            $paymentExecution = PaymentExecution::whereKey($allocation->payment_execution_id)->first();
            $apJournal = JournalEntry::with('lines')->whereKey($allocation->ap_journal_entry_id)->first();
            $paymentJournal = JournalEntry::with('lines')->whereKey($allocation->payment_journal_entry_id)->first();

            if (!$paymentExecution || !$apJournal || !$paymentJournal) {
                return [
                    'eligible' => false,
                    'reason_code' => 'MISSING_PROVENANCE',
                ];
            }

            $supplierInvoice = SupplierInvoice::whereKey($paymentExecution->supplier_invoice_id)->first();
            if (!$supplierInvoice) {
                return [
                    'eligible' => false,
                    'reason_code' => 'MISSING_PROVENANCE',
                ];
            }

            // Conflicting provenance checks
            if ($paymentExecution->property_id !== $propertyId ||
                $apJournal->property_id !== $propertyId ||
                $paymentJournal->property_id !== $propertyId ||
                $supplierInvoice->property_id !== $propertyId ||
                $paymentExecution->supplier_invoice_id !== $supplierInvoice->id ||
                $paymentExecution->source_journal_entry_id !== $apJournal->id) {
                return [
                    'eligible' => false,
                    'reason_code' => 'CONFLICTING_PROVENANCE',
                ];
            }

            // 5. Basis checks
            // Resolve AP Control mapping on invoice date
            $invoiceDate = $supplierInvoice->invoice_date;
            if (!$invoiceDate) {
                return [
                    'eligible' => false,
                    'reason_code' => 'MISSING_DATE',
                ];
            }

            try {
                $apControlMapping = $this->mappingService->resolve($propertyId, OperationalIdentityEnum::AP_CONTROL, $invoiceDate);
            } catch (OperationalIdentityMappingNotFoundException) {
                return [
                    'eligible' => false,
                    'reason_code' => 'INVALID_MAPPING',
                ];
            }

            // Verify AP Journal has credit line on AP Control account
            $hasApControlLine = $apJournal->lines->contains(function ($line) use ($apControlMapping): bool {
                return $line->account_id === $apControlMapping->account_id &&
                    $line->credit_amount !== null &&
                    $line->credit_amount > 0;
            });

            if (!$hasApControlLine) {
                return [
                    'eligible' => false,
                    'reason_code' => 'MISSING_BASIS',
                ];
            }

            // Resolve Cash mapping on payment date
            $paymentDate = $paymentExecution->executed_at;
            if (!$paymentDate) {
                return [
                    'eligible' => false,
                    'reason_code' => 'MISSING_DATE',
                ];
            }

            try {
                $cashMapping = $this->mappingService->resolve($propertyId, OperationalIdentityEnum::CASH_AND_BANK, $paymentDate);
            } catch (OperationalIdentityMappingNotFoundException) {
                return [
                    'eligible' => false,
                    'reason_code' => 'INVALID_MAPPING',
                ];
            }

            // Verify Payment Journal has credit line on Cash account
            $hasCashLine = $paymentJournal->lines->contains(function ($line) use ($cashMapping): bool {
                return $line->account_id === $cashMapping->account_id &&
                    $line->credit_amount !== null &&
                    $line->credit_amount > 0;
            });

            if (!$hasCashLine) {
                return [
                    'eligible' => false,
                    'reason_code' => 'MISSING_BASIS',
                ];
            }

            // 6. Currency checks
            $invoiceCurrency = $supplierInvoice->currency_code;
            $paymentCurrency = $paymentExecution->currency_code;

            if (empty($invoiceCurrency) || empty($paymentCurrency)) {
                return [
                    'eligible' => false,
                    'reason_code' => 'MISSING_CURRENCY',
                ];
            }

            if ($invoiceCurrency === $paymentCurrency) {
                return [
                    'eligible' => false,
                    'reason_code' => 'SAME_CURRENCY',
                ];
            }

            // 7. Date checks
            $allocationDate = $allocation->allocated_at;
            if (!$allocationDate) {
                return [
                    'eligible' => false,
                    'reason_code' => 'MISSING_DATE',
                ];
            }

            // 8. Approved Rate Evidence check
            $rateEvidence = ExchangeRateEvidence::where('property_id', $propertyId)
                ->where('base_currency', $invoiceCurrency)
                ->where('quote_currency', $paymentCurrency)
                ->where('effective_date', $paymentDate->toDateString())
                ->where('status', ExchangeRateEvidenceStatusEnum::APPROVED)
                ->first();

            if (!$rateEvidence) {
                return [
                    'eligible' => false,
                    'reason_code' => 'NO_APPROVED_RATE_EVIDENCE',
                ];
            }

            // 9. FX Mapping & validation resolution checks (including ambiguity checks)
            try {
                $fxGainMapping = $this->mappingService->resolve($propertyId, OperationalIdentityEnum::FX_GAIN, $paymentDate);
                $this->identityValidationService->validate(OperationalIdentityEnum::FX_GAIN, $fxGainMapping->account);

                $fxLossMapping = $this->mappingService->resolve($propertyId, OperationalIdentityEnum::FX_LOSS, $paymentDate);
                $this->identityValidationService->validate(OperationalIdentityEnum::FX_LOSS, $fxLossMapping->account);
            } catch (OperationalIdentityMappingNotFoundException | OperationalIdentityValidationException) {
                return [
                    'eligible' => false,
                    'reason_code' => 'INVALID_MAPPING',
                ];
            }

            // Ambiguity check
            $gainCount = OperationalIdentityMapping::where('property_id', $propertyId)
                ->where('operational_identity', OperationalIdentityEnum::FX_GAIN->value)
                ->where('is_active', true)
                ->where('effective_from', '<=', $paymentDate->toDateString())
                ->where(function ($query) use ($paymentDate) {
                    $query->whereNull('effective_to')
                        ->orWhere('effective_to', '>=', $paymentDate->toDateString());
                })
                ->whereNull('cost_center_id')
                ->count();

            $lossCount = OperationalIdentityMapping::where('property_id', $propertyId)
                ->where('operational_identity', OperationalIdentityEnum::FX_LOSS->value)
                ->where('is_active', true)
                ->where('effective_from', '<=', $paymentDate->toDateString())
                ->where(function ($query) use ($paymentDate) {
                    $query->whereNull('effective_to')
                        ->orWhere('effective_to', '>=', $paymentDate->toDateString());
                })
                ->whereNull('cost_center_id')
                ->count();

            if ($gainCount > 1 || $lossCount > 1) {
                return [
                    'eligible' => false,
                    'reason_code' => 'AMBIGUOUS_MAPPING',
                ];
            }

            // Everything passes, construct read-only result
            return [
                'eligible' => true,
                'reason_code' => 'ELIGIBLE',
                'source_owned_ids' => [
                    'property_id' => $propertyId,
                    'vendor_id' => $allocation->vendor_id,
                    'allocation_id' => $allocation->id,
                    'ap_journal_entry_id' => $allocation->ap_journal_entry_id,
                    'payment_journal_entry_id' => $allocation->payment_journal_entry_id,
                    'payment_execution_id' => $allocation->payment_execution_id,
                    'supplier_invoice_id' => $supplierInvoice->id,
                ],
                'source_owned_currency_codes' => [
                    'invoice_currency' => $invoiceCurrency,
                    'payment_currency' => $paymentCurrency,
                ],
                'source_owned_dates' => [
                    'invoice_date' => $invoiceDate->toDateString(),
                    'payment_date' => $paymentDate->toDateString(),
                    'allocation_date' => $allocationDate->toDateString(),
                ],
                'approved_exchange_rate_evidence_id' => $rateEvidence->id,
                'fx_gain_mapping_id' => $fxGainMapping->id,
                'fx_loss_mapping_id' => $fxLossMapping->id,
                'immutable_evidence_snapshots' => [
                    'allocation_snapshot' => [
                        'id' => $allocation->id,
                        'property_id' => $allocation->property_id,
                        'vendor_id' => $allocation->vendor_id,
                        'currency_code' => $allocation->currency_code,
                        'ap_journal_entry_id' => $allocation->ap_journal_entry_id,
                        'payment_journal_entry_id' => $allocation->payment_journal_entry_id,
                        'payment_execution_id' => $allocation->payment_execution_id,
                        'allocation_amount' => $allocation->allocation_amount,
                        'allocated_by' => $allocation->allocated_by,
                        'allocated_at' => $allocation->allocated_at?->toIso8601String(),
                    ],
                    'exchange_rate_evidence_snapshot' => [
                        'id' => $rateEvidence->id,
                        'property_id' => $rateEvidence->property_id,
                        'base_currency' => $rateEvidence->base_currency,
                        'quote_currency' => $rateEvidence->quote_currency,
                        'rate' => $rateEvidence->rate,
                        'effective_date' => $rateEvidence->effective_date?->toDateString(),
                        'status' => $rateEvidence->status->value,
                    ],
                    'fx_gain_mapping_snapshot' => [
                        'id' => $fxGainMapping->id,
                        'property_id' => $fxGainMapping->property_id,
                        'operational_identity' => $fxGainMapping->operational_identity->value,
                        'account_id' => $fxGainMapping->account_id,
                        'is_active' => (bool)$fxGainMapping->is_active,
                    ],
                    'fx_loss_mapping_snapshot' => [
                        'id' => $fxLossMapping->id,
                        'property_id' => $fxLossMapping->property_id,
                        'operational_identity' => $fxLossMapping->operational_identity->value,
                        'account_id' => $fxLossMapping->account_id,
                        'is_active' => (bool)$fxLossMapping->is_active,
                    ],
                ],
            ];
        } catch (Throwable $e) {
            return [
                'eligible' => false,
                'reason_code' => 'ERROR: ' . $e->getMessage(),
            ];
        }
    }
}
