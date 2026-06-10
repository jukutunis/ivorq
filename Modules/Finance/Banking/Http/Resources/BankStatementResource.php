<?php

namespace Modules\Finance\Banking\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankStatementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_account_id' => $this->bank_account_id,
            'statement_date' => $this->statement_date->format('Y-m-d'),
            'opening_balance' => $this->opening_balance,
            'closing_balance' => $this->closing_balance,
            'imported_closing_balance' => $this->imported_closing_balance,
            'status' => $this->status->value,
            'lines' => BankStatementLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at,
        ];
    }
}
