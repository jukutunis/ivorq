<?php

namespace Modules\Finance\Payables\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountPayableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'vendor_id' => $this->vendor_id,
            'vendor_invoice_id' => $this->vendor_invoice_id,
            'payable_no' => $this->payable_no,
            'invoice_date' => $this->invoice_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,
            'amount' => $this->amount,
            'outstanding_amount' => $this->outstanding_amount,
            'status' => $this->status,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
