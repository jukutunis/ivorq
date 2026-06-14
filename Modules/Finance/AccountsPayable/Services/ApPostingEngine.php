<?php

namespace Modules\Finance\AccountsPayable\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceTypeEnum;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityMappingService;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityValidationService;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityMappingNotFoundException;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityValidationException;

class ApPostingEngine
{
    public function __construct(
        private InvoiceVarianceCalculationEngine $varianceEngine,
        private OperationalIdentityMappingService $mappingService,
        private OperationalIdentityValidationService $validationService
    ) {}

    public function processInvoice(ApInvoice $invoice): void
    {
        // Must be APPROVED to post
        if ($invoice->status !== ApInvoiceStatusEnum::APPROVED) {
            return;
        }

        $propertyId = $invoice->property_id;
        $date = $invoice->invoice_date;

        // Idempotency: Create Candidate
        $candidate = JournalCandidate::firstOrCreate([
            'source_type' => ApInvoice::class,
            'source_id' => $invoice->id,
            'posting_event' => 'ApInvoiceApproved',
        ], [
            'property_id' => $propertyId,
            'status' => JournalCandidateStatusEnum::DRAFT->value,
            'candidate_date' => $date->toDateString(),
            'description' => "AP Invoice {$invoice->vendor_invoice_number} from {$invoice->vendor->name}",
            'metadata' => [
                'invoice_id' => $invoice->id,
                'vendor_id' => $invoice->vendor_id,
            ],
            'created_by' => $invoice->approved_by,
        ]);

        if (!in_array($candidate->status, [JournalCandidateStatusEnum::DRAFT, JournalCandidateStatusEnum::CONFIGURATION_ERROR])) {
            return;
        }

        try {
            DB::transaction(function () use ($candidate, $invoice, $propertyId, $date) {
                $candidate->lines()->delete();

                $linesToCreate = [];

                if ($invoice->invoice_type === ApInvoiceTypeEnum::DIRECT_EXPENSE) {
                    $linesToCreate = $this->buildDirectExpenseLines($invoice);
                } else {
                    $linesToCreate = $this->buildGrniMatchedLines($invoice);
                }

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

            // If the candidate processed successfully, update the invoice to POSTED.
            // Wait, does the system auto post it? No, JournalCandidate needs review before actual GL posting.
            // However, the Invoice is "POSTED" meaning it's officially submitted to finance.
            $invoice->update([
                'status' => ApInvoiceStatusEnum::POSTED,
                'posted_at' => now(),
            ]);

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

    private function buildDirectExpenseLines(ApInvoice $invoice): array
    {
        $lines = [];

        $lines[] = [
            'operational_identity' => OperationalIdentityEnum::OPERATIONAL_EXPENSE->value,
            'entry_type' => EntryTypeEnum::DEBIT->value,
            'amount' => $invoice->subtotal_amount,
        ];

        if ($invoice->tax_amount > 0) {
            $lines[] = [
                'operational_identity' => OperationalIdentityEnum::VENDOR_TAX->value,
                'entry_type' => EntryTypeEnum::DEBIT->value,
                'amount' => $invoice->tax_amount,
            ];
        }

        $lines[] = [
            'operational_identity' => OperationalIdentityEnum::AP_CONTROL->value,
            'entry_type' => EntryTypeEnum::CREDIT->value,
            'amount' => $invoice->grand_total_amount,
        ];

        return $lines;
    }

    private function buildGrniMatchedLines(ApInvoice $invoice): array
    {
        $lines = [];

        $totalMatchedAmount = 0.0;
        $totalVarianceDebit = 0.0;
        $totalVarianceCredit = 0.0;

        foreach ($invoice->lines as $line) {
            $varianceResult = $this->varianceEngine->calculate(
                $line->quantity,
                $line->unit_price,
                $line->receiptLine->unit_cost
            );

            $totalMatchedAmount += $varianceResult['matched_amount'];

            if ($varianceResult['variance_amount'] > 0) {
                $totalVarianceDebit += $varianceResult['variance_amount'];
            } elseif ($varianceResult['variance_amount'] < 0) {
                $totalVarianceCredit += abs($varianceResult['variance_amount']);
            }
        }

        if ($totalMatchedAmount > 0) {
            $lines[] = [
                'operational_identity' => OperationalIdentityEnum::GRNI_ACCRUAL->value,
                'entry_type' => EntryTypeEnum::DEBIT->value,
                'amount' => round($totalMatchedAmount, 3),
            ];
        }

        if ($totalVarianceDebit > 0) {
            $lines[] = [
                'operational_identity' => OperationalIdentityEnum::AP_INVOICE_VARIANCE->value,
                'entry_type' => EntryTypeEnum::DEBIT->value,
                'amount' => round($totalVarianceDebit, 3),
            ];
        }

        if ($totalVarianceCredit > 0) {
            $lines[] = [
                'operational_identity' => OperationalIdentityEnum::AP_INVOICE_VARIANCE->value,
                'entry_type' => EntryTypeEnum::CREDIT->value,
                'amount' => round($totalVarianceCredit, 3),
            ];
        }

        if ($invoice->tax_amount > 0) {
            $lines[] = [
                'operational_identity' => OperationalIdentityEnum::VENDOR_TAX->value,
                'entry_type' => EntryTypeEnum::DEBIT->value,
                'amount' => $invoice->tax_amount,
            ];
        }

        $lines[] = [
            'operational_identity' => OperationalIdentityEnum::AP_CONTROL->value,
            'entry_type' => EntryTypeEnum::CREDIT->value,
            'amount' => $invoice->grand_total_amount,
        ];

        return $lines;
    }
}
