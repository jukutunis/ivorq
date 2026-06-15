<?php

namespace Modules\Finance\Treasury\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Treasury\Models\VendorPayment;
use Modules\Finance\Treasury\Enums\VendorPaymentStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityMappingService;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityValidationService;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityMappingNotFoundException;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityValidationException;

class PaymentPostingEngine
{
    public function __construct(
        private OperationalIdentityMappingService $mappingService,
        private OperationalIdentityValidationService $validationService
    ) {}

    public function processPayment(VendorPayment $payment): void
    {
        // Must be EXECUTED to post according to safest configuration.
        // Wait, does the prompt say "recommend safest option"? Yes.
        if ($payment->status !== VendorPaymentStatusEnum::Executed) {
            return;
        }

        $propertyId = $payment->property_id;
        $date = $payment->payment_date;

        // Idempotency: Create Candidate
        $candidate = JournalCandidate::firstOrCreate([
            'source_type' => VendorPayment::class,
            'source_id' => $payment->id,
            'posting_event' => 'VendorPaymentExecuted',
        ], [
            'property_id' => $propertyId,
            'status' => JournalCandidateStatusEnum::DRAFT->value,
            'candidate_date' => $date->toDateString(),
            'description' => "Vendor Payment {$payment->payment_number} to Vendor {$payment->vendor->name}",
            'metadata' => [
                'vendor_payment_id' => $payment->id,
                'vendor_id' => $payment->vendor_id,
            ],
            'created_by' => $payment->created_by,
        ]);

        if (!in_array($candidate->status, [JournalCandidateStatusEnum::DRAFT, JournalCandidateStatusEnum::CONFIGURATION_ERROR])) {
            return;
        }

        try {
            DB::transaction(function () use ($candidate, $payment, $propertyId, $date) {
                $candidate->lines()->delete();

                $linesToCreate = $this->buildPaymentLines($payment);

                // Resolve mappings and validate
                foreach ($linesToCreate as $lineData) {
                    $identity = OperationalIdentityEnum::from($lineData['operational_identity']);
                    
                    $mapping = $this->mappingService->resolve($propertyId, $identity, $date);
                    $this->validationService->validate($identity, $mapping->account);

                    $candidate->lines()->create([
                        'operational_identity' => $identity->value,
                        'entry_type' => $lineData['entry_type'],
                        'amount' => $lineData['amount'],
                        'cost_center_id' => $mapping->cost_center_id,
                    ]);
                }

                $candidate->update([
                    'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
                    'metadata' => array_merge($candidate->metadata ?? [], ['mapping_error' => null])
                ]);
            });

        } catch (OperationalIdentityMappingNotFoundException | OperationalIdentityValidationException $e) {
            $this->logError($candidate, $e);
        } catch (Exception $e) {
            $this->logError($candidate, $e);
        }
    }

    private function logError(JournalCandidate $candidate, Exception $e): void
    {
        $metadata = $candidate->metadata ?? [];
        $metadata['mapping_error'] = [
            'type' => class_basename($e),
            'message' => $e->getMessage(),
            'timestamp' => now()->toIso8601String(),
        ];

        $candidate->update([
            'status' => JournalCandidateStatusEnum::CONFIGURATION_ERROR->value,
            'metadata' => $metadata,
        ]);
    }

    private function buildPaymentLines(VendorPayment $payment): array
    {
        $lines = [];

        // 1. AP Payment (Debit) - Sum of allocations
        $allocatedAmount = $payment->allocations()->sum('allocated_amount');

        if ($allocatedAmount > 0) {
            $lines[] = [
                'operational_identity' => OperationalIdentityEnum::AP_PAYMENT->value,
                'entry_type' => EntryTypeEnum::DEBIT->value,
                'amount' => round($allocatedAmount, 2),
            ];
        }

        // 2. Bank Disbursement (Credit) - Total amount leaving the bank
        if ($payment->total_amount > 0) {
            $lines[] = [
                'operational_identity' => OperationalIdentityEnum::BANK_DISBURSEMENT->value,
                'entry_type' => EntryTypeEnum::CREDIT->value,
                'amount' => round($payment->total_amount, 2),
            ];
        }

        // 3. Bank Fee (Debit)
        if ($payment->bank_fee_amount > 0) {
            $lines[] = [
                'operational_identity' => OperationalIdentityEnum::BANK_FEE->value,
                'entry_type' => EntryTypeEnum::DEBIT->value,
                'amount' => round($payment->bank_fee_amount, 2),
            ];
        }

        // 4. Payment Variance (Debit or Credit)
        // Variance calculation: Total Credits - Total Debits
        // If variance is positive, we need a Debit to balance.
        // If variance is negative, we need a Credit to balance.
        // Debits: AP_PAYMENT + BANK_FEE
        // Credits: BANK_DISBURSEMENT
        $totalDebits = $allocatedAmount + $payment->bank_fee_amount;
        $totalCredits = $payment->total_amount;
        $variance = $totalCredits - $totalDebits;

        if (round($variance, 2) > 0) {
            // Need Debit
            $lines[] = [
                'operational_identity' => OperationalIdentityEnum::PAYMENT_VARIANCE->value,
                'entry_type' => EntryTypeEnum::DEBIT->value,
                'amount' => round($variance, 2),
            ];
        } elseif (round($variance, 2) < 0) {
            // Need Credit
            $lines[] = [
                'operational_identity' => OperationalIdentityEnum::PAYMENT_VARIANCE->value,
                'entry_type' => EntryTypeEnum::CREDIT->value,
                'amount' => round(abs($variance), 2),
            ];
        }

        return $lines;
    }
}
