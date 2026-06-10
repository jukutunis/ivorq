<?php

namespace Modules\Finance\Payables\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Payables\Enums\AccountPayableStatusEnum;
use Modules\Finance\Payables\Enums\VendorInvoiceStatusEnum;
use Modules\Finance\Payables\Models\AccountPayable;
use Modules\Finance\Payables\Models\VendorInvoice;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccountPayableService
{
    /**
     * Generates an Accounts Payable record from a successfully matched VendorInvoice.
     *
     * @param VendorInvoice $invoice
     * @return AccountPayable
     * @throws HttpException|\Exception
     */
    public function createFromMatchedInvoice(VendorInvoice $invoice): AccountPayable
    {
        if ($invoice->status !== VendorInvoiceStatusEnum::Matched) {
            abort(400, "Only matched invoices can generate AP records.");
        }

        if (AccountPayable::where('vendor_invoice_id', $invoice->id)->exists()) {
            abort(400, "Accounts Payable already generated for this invoice.");
        }

        return DB::transaction(function () use ($invoice) {
            $year = now()->format('Y');
            
            // Locking the latest AP record for the property and year to ensure sequential numbering
            $latestAP = AccountPayable::where('property_id', $invoice->property_id)
                ->where('payable_no', 'like', "AP-{$year}-%")
                ->lockForUpdate()
                ->orderBy('payable_no', 'desc')
                ->first();

            $sequence = 1;
            if ($latestAP) {
                $parts = explode('-', $latestAP->payable_no);
                $sequence = (int)end($parts) + 1;
            }

            $payableNo = "AP-{$year}-" . str_pad($sequence, 6, '0', STR_PAD_LEFT);

            // Inherit exchange rate or default to 1.0000
            $exchangeRate = $invoice->exchange_rate ?? 1.0000;

            // Remarks default
            $remarks = "Generated from Vendor Invoice {$invoice->invoice_number}";

            return AccountPayable::create([
                'property_id' => $invoice->property_id,
                'vendor_id' => $invoice->vendor_id,
                'vendor_invoice_id' => $invoice->id,
                'payable_no' => $payableNo,
                'invoice_date' => $invoice->invoice_date,
                'due_date' => $invoice->due_date,
                'currency_code' => $invoice->currency_code ?? 'IDR',
                'exchange_rate' => $exchangeRate,
                'amount' => $invoice->grand_total,
                'outstanding_amount' => $invoice->grand_total,
                'status' => AccountPayableStatusEnum::Open,
                'remarks' => $remarks,
            ]);
        });
    }
}
