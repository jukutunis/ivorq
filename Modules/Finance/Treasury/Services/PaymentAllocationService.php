<?php

namespace Modules\Finance\Treasury\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Exception;
use Modules\Finance\AccountsPayable\Enums\InvoicePaymentStatusEnum;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Finance\Treasury\Models\PaymentAllocation;
use Modules\Finance\Treasury\Models\VendorPayment;
use Modules\Finance\Treasury\Enums\VendorPaymentStatusEnum;

class PaymentAllocationService
{
    /**
     * Allocates a payment amount to a specific AP Invoice.
     * Ensures concurrency protection and prevents overpayment.
     *
     * @param VendorPayment $payment
     * @param string $invoiceId
     * @param float $allocateAmount
     * @return PaymentAllocation
     * @throws Exception
     */
    public function allocatePayment(VendorPayment $payment, string $invoiceId, float $allocateAmount): PaymentAllocation
    {
        if ($allocateAmount <= 0) {
            throw new InvalidArgumentException("Allocation amount must be greater than zero.");
        }

        if ($payment->status !== VendorPaymentStatusEnum::Draft) {
            throw new Exception("Cannot allocate payments unless the VendorPayment is in DRAFT status.");
        }

        return DB::transaction(function () use ($payment, $invoiceId, $allocateAmount) {
            // Concurrency Protection: Lock the row for update
            $invoice = ApInvoice::where('id', $invoiceId)
                ->where('property_id', $payment->property_id) // Property Isolation
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->vendor_id !== $payment->vendor_id) {
                throw new Exception("Invoice vendor does not match payment vendor.");
            }

            if ($allocateAmount > $invoice->amount_remaining) {
                throw new Exception("Overpayment prevention: Cannot allocate {$allocateAmount}. Remaining amount is {$invoice->amount_remaining}.");
            }

            // Create the allocation
            $allocation = PaymentAllocation::create([
                'property_id' => $payment->property_id,
                'vendor_payment_id' => $payment->id,
                'ap_invoice_id' => $invoice->id,
                'allocated_amount' => $allocateAmount,
            ]);

            // Update Invoice Totals
            $invoice->amount_paid += $allocateAmount;
            $invoice->amount_remaining -= $allocateAmount;

            // Determine Payment Status
            if ($invoice->amount_remaining <= 0) {
                $invoice->payment_status = InvoicePaymentStatusEnum::Paid;
            } else {
                $invoice->payment_status = InvoicePaymentStatusEnum::PartiallyPaid;
            }

            $invoice->save();

            // Note: The Payment's total_amount should equal SUM(allocations) before it can be APPROVED.
            // This is verified during the Approval lifecycle, not necessarily per allocation.

            return $allocation;
        });
    }

    /**
     * Removes an allocation and restores the AP Invoice balance.
     *
     * @param string $allocationId
     * @return void
     */
    public function removeAllocation(string $allocationId): void
    {
        DB::transaction(function () use ($allocationId) {
            $allocation = PaymentAllocation::where('id', $allocationId)->lockForUpdate()->firstOrFail();
            $payment = VendorPayment::where('id', $allocation->vendor_payment_id)->firstOrFail();

            if ($payment->status !== VendorPaymentStatusEnum::Draft) {
                throw new Exception("Cannot remove allocation unless the VendorPayment is in DRAFT status.");
            }

            $invoice = ApInvoice::where('id', $allocation->ap_invoice_id)
                ->lockForUpdate()
                ->firstOrFail();

            $invoice->amount_paid -= $allocation->allocated_amount;
            $invoice->amount_remaining += $allocation->allocated_amount;

            if ($invoice->amount_paid <= 0) {
                $invoice->payment_status = InvoicePaymentStatusEnum::Unpaid;
                $invoice->amount_paid = 0; // ensure no float drift below 0
            } else {
                $invoice->payment_status = InvoicePaymentStatusEnum::PartiallyPaid;
            }

            $invoice->save();
            $allocation->delete();
        });
    }
}
