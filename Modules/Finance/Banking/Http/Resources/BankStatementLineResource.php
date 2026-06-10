<?php

namespace Modules\Finance\Banking\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankStatementLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_date' => $this->transaction_date->format('Y-m-d'),
            'description' => $this->description,
            'reference' => $this->reference,
            'external_reference' => $this->external_reference,
            'amount' => $this->amount,
            'is_reconciled' => $this->is_reconciled,
        ];
    }
}
