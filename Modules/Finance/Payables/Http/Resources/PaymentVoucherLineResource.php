<?php

namespace Modules\Finance\Payables\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentVoucherLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_voucher_id' => $this->payment_voucher_id,
            'account_payable_id' => $this->account_payable_id,
            'amount_paid' => $this->amount_paid,
            'remarks' => $this->remarks,
            'ap_payable_no' => $this->ap_payable_no,
            'ap_original_amount' => $this->ap_original_amount,
            'ap_outstanding_before' => $this->ap_outstanding_before,
            'ap_outstanding_after' => $this->ap_outstanding_after,
            'created_at' => $this->created_at,
        ];
    }
}
