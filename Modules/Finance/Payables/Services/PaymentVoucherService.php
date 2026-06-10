<?php

namespace Modules\Finance\Payables\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Payables\Enums\AccountPayableStatusEnum;
use Modules\Finance\Payables\Enums\PaymentVoucherStatusEnum;
use Modules\Finance\Payables\Models\AccountPayable;
use Modules\Finance\Payables\Models\PaymentVoucher;
use Modules\Finance\Payables\Models\PaymentVoucherLine;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentVoucherService
{
    /**
     * Create a new draft Payment Voucher.
     *
     * @param array $data Expected keys: property_id, vendor_id, payment_date, payment_method, reference_no, remarks, lines (array of [account_payable_id, amount_paid, remarks])
     * @return PaymentVoucher
     * @throws HttpException|\Exception
     */
    public function create(array $data): PaymentVoucher
    {
        return DB::transaction(function () use ($data) {
            $year = date('Y', strtotime($data['payment_date']));
            
            // Auto-generate voucher number
            $latestPV = PaymentVoucher::where('property_id', $data['property_id'])
                ->where('voucher_no', 'like', "PV-{$year}-%")
                ->lockForUpdate()
                ->orderBy('voucher_no', 'desc')
                ->first();

            $sequence = 1;
            if ($latestPV) {
                $parts = explode('-', $latestPV->voucher_no);
                $sequence = (int)end($parts) + 1;
            }

            $voucherNo = "PV-{$year}-" . str_pad($sequence, 6, '0', STR_PAD_LEFT);

            $pv = PaymentVoucher::create([
                'property_id' => $data['property_id'],
                'vendor_id' => $data['vendor_id'],
                'voucher_no' => $voucherNo,
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'reference_no' => $data['reference_no'] ?? null,
                'total_amount' => 0, // will be calculated below
                'status' => PaymentVoucherStatusEnum::Draft,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $totalAmount = 0;

            foreach ($data['lines'] as $lineData) {
                $ap = AccountPayable::where('id', $lineData['account_payable_id'])
                    ->where('property_id', $data['property_id'])
                    ->where('vendor_id', $data['vendor_id'])
                    ->firstOrFail();

                if (!in_array($ap->status, [AccountPayableStatusEnum::Open, AccountPayableStatusEnum::PartiallyPaid])) {
                    abort(400, "Cannot pay an AP that is not Open or PartiallyPaid.");
                }

                // Strictly validate amount using bccomp
                if (bccomp((string)$lineData['amount_paid'], (string)$ap->outstanding_amount, 2) > 0) {
                    abort(400, "Payment amount cannot exceed AP outstanding amount.");
                }

                // Notice we do NOT deduct outstanding or change status here because this is just a draft.
                // Outstanding changes ONLY upon POST.

                PaymentVoucherLine::create([
                    'payment_voucher_id' => $pv->id,
                    'account_payable_id' => $ap->id,
                    'amount_paid' => $lineData['amount_paid'],
                    'remarks' => $lineData['remarks'] ?? null,
                    'ap_payable_no' => $ap->payable_no,
                    'ap_original_amount' => $ap->amount,
                    'ap_outstanding_before' => $ap->outstanding_amount,
                    // ap_outstanding_after is null until posted
                ]);

                $totalAmount += $lineData['amount_paid'];
            }

            $pv->update(['total_amount' => $totalAmount]);

            return $pv->load('lines');
        });
    }

    /**
     * Post a Payment Voucher.
     */
    public function post(PaymentVoucher $pv): PaymentVoucher
    {
        if ($pv->status !== PaymentVoucherStatusEnum::Draft) {
            abort(400, "Only Draft Payment Vouchers can be posted.");
        }

        return DB::transaction(function () use ($pv) {
            $pv->load('lines.accountPayable');

            foreach ($pv->lines as $line) {
                // Lock the AP for update to prevent concurrent payment anomalies
                $ap = AccountPayable::where('id', $line->account_payable_id)->lockForUpdate()->firstOrFail();

                // Re-validate outstanding just in case another payment was posted while this was in draft
                if (bccomp((string)$line->amount_paid, (string)$ap->outstanding_amount, 2) > 0) {
                    abort(400, "Payment amount for AP {$ap->payable_no} exceeds current outstanding amount.");
                }

                $newOutstanding = bcsub((string)$ap->outstanding_amount, (string)$line->amount_paid, 2);
                
                $status = bccomp($newOutstanding, '0.00', 2) === 0 ? AccountPayableStatusEnum::Paid : AccountPayableStatusEnum::PartiallyPaid;

                $ap->update([
                    'outstanding_amount' => $newOutstanding,
                    'status' => $status,
                ]);

                $line->update([
                    'ap_outstanding_after' => $newOutstanding,
                ]);
            }

            $pv->update(['status' => PaymentVoucherStatusEnum::Posted]);

            return $pv;
        });
    }

    /**
     * Cancel a Payment Voucher (Reversal).
     */
    public function cancel(PaymentVoucher $pv): PaymentVoucher
    {
        if ($pv->status !== PaymentVoucherStatusEnum::Posted) {
            abort(400, "Only Posted Payment Vouchers can be cancelled.");
        }

        return DB::transaction(function () use ($pv) {
            $pv->load('lines.accountPayable');

            foreach ($pv->lines as $line) {
                // Lock the AP for update
                $ap = AccountPayable::where('id', $line->account_payable_id)->lockForUpdate()->firstOrFail();

                // Reverse the payment
                $newOutstanding = bcadd((string)$ap->outstanding_amount, (string)$line->amount_paid, 2);

                $status = bccomp($newOutstanding, (string)$ap->amount, 2) === 0 ? AccountPayableStatusEnum::Open : AccountPayableStatusEnum::PartiallyPaid;

                $ap->update([
                    'outstanding_amount' => $newOutstanding,
                    'status' => $status,
                ]);
            }

            $pv->update(['status' => PaymentVoucherStatusEnum::Cancelled]);

            return $pv;
        });
    }
}
