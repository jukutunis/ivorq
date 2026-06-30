<?php

namespace Modules\Finance\Payables\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Payables\Enums\MatchStatusEnum;
use Modules\Finance\Payables\Models\SupplierInvoice;

class GrniClearingAllocationEligibilityService
{
    public const DECISION_ELIGIBLE = 'ELIGIBLE_FOR_FUTURE_ALLOCATION';
    public const DECISION_BLOCKED = 'BLOCKED';

    public function evaluate(string $supplierInvoiceId): array
    {
        $invoice = SupplierInvoice::with(['lines', 'threeWayMatch.lines'])
            ->whereKey($supplierInvoiceId)
            ->first();

        if (!$invoice) {
            return $this->blocked($supplierInvoiceId, null, [], ['invoice_not_found']);
        }

        $blockers = [];
        $lineEvidence = [];
        $sourceEvidence = [
            'supplier_invoice_id' => $invoice->id,
            'purchase_order_id' => $invoice->purchase_order_id,
            'receiving_document_id' => $invoice->goods_receipt_id,
            'grni_candidate_id' => null,
            'posted_journal_entry_id' => null,
        ];

        if ($invoice->status !== SupplierInvoice::STATUS_APPROVED) {
            $blockers[] = 'invoice_not_approved';
        }

        if (strtoupper((string) $invoice->currency_code) !== 'IDR') {
            $blockers[] = 'unsupported_currency_condition';
        }

        if (!$this->isZero($invoice->tax_amount)) {
            $blockers[] = 'unsupported_tax_condition';
        }

        if (!$this->isZero($invoice->discount_amount)) {
            $blockers[] = 'unsupported_discount_condition';
        }

        $match = $invoice->threeWayMatch;
        $matchStatus = $match ? $this->enumValue($match->status) : null;

        if (!$match) {
            $blockers[] = 'missing_three_way_match';
        } elseif ($matchStatus !== MatchStatusEnum::Matched->value) {
            $blockers[] = 'match_not_matched';
        }

        if ($invoice->lines->count() !== 1) {
            $blockers[] = 'unsupported_multi_source_lineage';
        }

        if ($invoice->lines->count() === 1) {
            $lineResult = $this->evaluateSingleLine($invoice);
            $lineEvidence = $lineResult['lines'];
            $sourceEvidence = array_replace($sourceEvidence, $lineResult['source_evidence']);
            $blockers = array_merge($blockers, $lineResult['blockers']);
        }

        $blockers = array_values(array_unique($blockers));

        $result = [
            'decision' => $blockers === []
                ? self::DECISION_ELIGIBLE
                : self::DECISION_BLOCKED,
            'invoice_id' => $invoice->id,
            'invoice_status' => $invoice->status,
            'match_status' => $matchStatus,
            'source_currency' => strtoupper((string) $invoice->currency_code),
            'blockers' => $blockers,
            'source_evidence' => $sourceEvidence,
            'lines' => $lineEvidence,
        ];

        if ($result['decision'] === self::DECISION_BLOCKED) {
            $result['source_evidence']['grni_candidate_id'] = $sourceEvidence['grni_candidate_id'];
            $result['source_evidence']['posted_journal_entry_id'] = $sourceEvidence['posted_journal_entry_id'];
        }

        return $result;
    }

    private function evaluateSingleLine(SupplierInvoice $invoice): array
    {
        $blockers = [];
        $invoiceLine = $invoice->lines->first();
        $lineEvidence = [
            'supplier_invoice_line_id' => $invoiceLine->id,
            'purchase_order_line_id' => $invoiceLine->purchase_order_line_id,
            'receiving_line_id' => $invoiceLine->goods_receipt_line_id,
            'inventory_receipt_line_id' => null,
            'inventory_item_id' => $invoiceLine->inventory_item_id,
        ];
        $sourceEvidence = [
            'purchase_order_id' => $invoice->purchase_order_id,
            'receiving_document_id' => $invoice->goods_receipt_id,
            'grni_candidate_id' => null,
            'posted_journal_entry_id' => null,
        ];

        $vendor = DB::table('vendors')
            ->where('id', $invoice->vendor_id)
            ->first();

        if (!$vendor || $vendor->property_id !== $invoice->property_id) {
            $blockers[] = 'vendor_scope_mismatch';
        }

        $purchaseOrder = DB::table('purchase_orders')
            ->where('id', $invoice->purchase_order_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$purchaseOrder) {
            $blockers[] = 'missing_purchase_order';
        } else {
            if ($purchaseOrder->property_id !== $invoice->property_id) {
                $blockers[] = 'purchase_order_property_mismatch';
            }

            if ($purchaseOrder->vendor_id !== $invoice->vendor_id) {
                $blockers[] = 'purchase_order_vendor_mismatch';
            }

            if (strtoupper((string) $purchaseOrder->currency_code) !== strtoupper((string) $invoice->currency_code)
                || !$this->isOne($purchaseOrder->exchange_rate)) {
                $blockers[] = 'unsupported_currency_condition';
            }
        }

        $purchaseOrderLine = DB::table('purchase_order_lines')
            ->where('id', $invoiceLine->purchase_order_line_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$purchaseOrderLine) {
            $blockers[] = 'missing_purchase_order_line';
        } elseif ($purchaseOrder && $purchaseOrderLine->purchase_order_id !== $purchaseOrder->id) {
            $blockers[] = 'purchase_order_line_mismatch';
        }

        $receivingDocument = DB::table('receiving_documents')
            ->where('id', $invoice->goods_receipt_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$receivingDocument) {
            $blockers[] = 'missing_receiving_document';
        } else {
            if ($receivingDocument->property_id !== $invoice->property_id) {
                $blockers[] = 'receiving_property_mismatch';
            }

            if ($receivingDocument->vendor_id !== $invoice->vendor_id) {
                $blockers[] = 'receiving_vendor_mismatch';
            }

            if ($purchaseOrder && $receivingDocument->purchase_order_id !== $purchaseOrder->id) {
                $blockers[] = 'receiving_purchase_order_mismatch';
            }

            if (DB::table('receiving_lines')->where('receiving_document_id', $receivingDocument->id)->count() !== 1) {
                $blockers[] = 'unsupported_multi_source_lineage';
            }
        }

        $receivingLine = DB::table('receiving_lines')
            ->where('id', $invoiceLine->goods_receipt_line_id)
            ->first();

        if (!$receivingLine) {
            $blockers[] = 'missing_receiving_line';
        } else {
            if ($receivingDocument && $receivingLine->receiving_document_id !== $receivingDocument->id) {
                $blockers[] = 'receiving_line_document_mismatch';
            }

            if ($purchaseOrderLine && $receivingLine->purchase_order_line_id !== $purchaseOrderLine->id) {
                $blockers[] = 'receiving_line_purchase_order_line_mismatch';
            }

            if ($receivingLine->inventory_item_id !== $invoiceLine->inventory_item_id) {
                $blockers[] = 'receiving_line_item_mismatch';
            }

            if (!$this->sameDecimal($invoiceLine->quantity, $receivingLine->received_quantity, 4)) {
                $blockers[] = 'unsupported_quantity_condition';
            }

            if ($purchaseOrderLine && !$this->sameDecimal($invoiceLine->unit_price, $purchaseOrderLine->unit_cost, 2)) {
                $blockers[] = 'unsupported_price_condition';
            }

            if (!$this->sameDecimal($invoiceLine->unit_price, $receivingLine->unit_cost, 2)
                || !$this->sameDecimal($invoiceLine->line_total, $receivingLine->line_total, 2)) {
                $blockers[] = 'unsupported_price_condition';
            }
        }

        if (!$receivingDocument) {
            return [
                'blockers' => $blockers,
                'source_evidence' => $sourceEvidence,
                'lines' => [$lineEvidence],
            ];
        }

        $inventoryReceipts = DB::table('inventory_receipts')
            ->where('property_id', $invoice->property_id)
            ->where('receiving_document_id', $receivingDocument->id)
            ->where('status', 'posted')
            ->whereNull('deleted_at')
            ->get();

        if ($inventoryReceipts->isEmpty()) {
            $blockers[] = 'missing_posted_inventory_receipt';

            return [
                'blockers' => $blockers,
                'source_evidence' => $sourceEvidence,
                'lines' => [$lineEvidence],
            ];
        }

        if ($inventoryReceipts->count() !== 1) {
            $blockers[] = 'ambiguous_grni_source';

            return [
                'blockers' => $blockers,
                'source_evidence' => $sourceEvidence,
                'lines' => [$lineEvidence],
            ];
        }

        $inventoryReceipt = $inventoryReceipts->first();
        $inventoryReceiptLines = DB::table('inventory_receipt_lines')
            ->where('property_id', $invoice->property_id)
            ->where('receipt_id', $inventoryReceipt->id)
            ->get();

        if ($inventoryReceiptLines->count() !== 1) {
            $blockers[] = 'unsupported_multi_source_lineage';
        } else {
            $inventoryReceiptLine = $inventoryReceiptLines->first();
            $lineEvidence['inventory_receipt_line_id'] = $inventoryReceiptLine->id;

            if ($receivingLine) {
                if ($inventoryReceiptLine->item_id !== $receivingLine->inventory_item_id
                    || $inventoryReceiptLine->location_id !== $receivingLine->destination_location_id) {
                    $blockers[] = 'inventory_receipt_line_mismatch';
                }

                if (!$this->sameDecimal($inventoryReceiptLine->quantity, $receivingLine->received_quantity, 4)) {
                    $blockers[] = 'unsupported_quantity_condition';
                }

                if (!$this->sameDecimal($inventoryReceiptLine->unit_cost, $receivingLine->unit_cost, 2)
                    || !$this->sameDecimal($inventoryReceiptLine->line_total, $receivingLine->line_total, 2)) {
                    $blockers[] = 'unsupported_price_condition';
                }
            }
        }

        $candidateResult = $this->grniCandidateEvidence($invoice->property_id, $inventoryReceipt->id);
        $blockers = array_merge($blockers, $candidateResult['blockers']);
        $sourceEvidence = array_replace($sourceEvidence, $candidateResult['source_evidence']);

        return [
            'blockers' => $blockers,
            'source_evidence' => $sourceEvidence,
            'lines' => [$lineEvidence],
        ];
    }

    private function grniCandidateEvidence(string $propertyId, string $inventoryReceiptId): array
    {
        $blockers = [];
        $sourceEvidence = [
            'grni_candidate_id' => null,
            'posted_journal_entry_id' => null,
        ];

        $candidates = DB::table('journal_candidates')
            ->where('property_id', $propertyId)
            ->where('source_type', 'InventoryReceipt')
            ->where('source_id', $inventoryReceiptId)
            ->where('posting_event', 'InventoryReceiptAccrual')
            ->get();

        if ($candidates->isEmpty()) {
            return [
                'blockers' => ['missing_grni_candidate'],
                'source_evidence' => $sourceEvidence,
            ];
        }

        if ($candidates->count() !== 1) {
            return [
                'blockers' => ['ambiguous_grni_candidate'],
                'source_evidence' => $sourceEvidence,
            ];
        }

        $candidate = $candidates->first();
        $sourceEvidence['grni_candidate_id'] = $candidate->id;

        if (!in_array($candidate->status, ['APPROVED', 'POSTED'], true)) {
            $blockers[] = 'grni_candidate_not_approved';
        }

        if (DB::table('journal_candidate_lines')->where('journal_candidate_id', $candidate->id)->count() === 0) {
            $blockers[] = 'missing_grni_candidate_lines';
        }

        $journalEntries = DB::table('gl_journal_entries')
            ->where('journal_candidate_id', $candidate->id)
            ->whereNull('deleted_at')
            ->get();

        if ($journalEntries->isEmpty()) {
            $blockers[] = 'missing_posted_journal_entry';

            return [
                'blockers' => $blockers,
                'source_evidence' => $sourceEvidence,
            ];
        }

        if ($journalEntries->count() !== 1) {
            $blockers[] = 'ambiguous_posted_journal_entry';

            return [
                'blockers' => $blockers,
                'source_evidence' => $sourceEvidence,
            ];
        }

        $journalEntry = $journalEntries->first();

        if ($journalEntry->property_id !== $propertyId
            || $journalEntry->source_type !== 'InventoryReceipt'
            || $journalEntry->source_id !== $inventoryReceiptId
            || $journalEntry->posting_event !== 'InventoryReceiptAccrual') {
            $blockers[] = 'journal_entry_source_mismatch';
        }

        if ($journalEntry->status !== 'Posted' || $journalEntry->posted_by === null || $journalEntry->posted_at === null) {
            $blockers[] = 'journal_entry_not_posted';
        } else {
            $sourceEvidence['posted_journal_entry_id'] = $journalEntry->id;
        }

        return [
            'blockers' => $blockers,
            'source_evidence' => $sourceEvidence,
        ];
    }

    private function blocked(string $invoiceId, ?string $invoiceStatus, array $lines, array $blockers): array
    {
        return [
            'decision' => self::DECISION_BLOCKED,
            'invoice_id' => $invoiceId,
            'invoice_status' => $invoiceStatus,
            'match_status' => null,
            'source_currency' => null,
            'blockers' => array_values(array_unique($blockers)),
            'source_evidence' => [
                'supplier_invoice_id' => $invoiceId,
                'purchase_order_id' => null,
                'receiving_document_id' => null,
                'grni_candidate_id' => null,
                'posted_journal_entry_id' => null,
            ],
            'lines' => $lines,
        ];
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof MatchStatusEnum) {
            return $value->value;
        }

        return $value === null ? null : (string) $value;
    }

    private function sameDecimal(mixed $left, mixed $right, int $scale): bool
    {
        return number_format((float) $left, $scale, '.', '') === number_format((float) $right, $scale, '.', '');
    }

    private function isZero(mixed $value): bool
    {
        return $this->sameDecimal($value, 0, 2);
    }

    private function isOne(mixed $value): bool
    {
        return $this->sameDecimal($value, 1, 4);
    }
}
