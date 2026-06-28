<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryReversalApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->hasPermissionTo('inventory.reversal.request');
    }

    public function rules(): array
    {
        return [
            'original_inventory_transaction_id' => ['required', 'string'],
            'reversal_reason'                  => ['required', 'string', 'min:1'],
            'request_idempotency_key'          => ['required', 'string', 'min:1'],
        ];
    }
}
