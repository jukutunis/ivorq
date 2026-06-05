<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Inventory\Models\InventoryReceipt;

class CancelReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $receipt = InventoryReceipt::find($this->route('receipt'));

        return $receipt && $this->user()->can('cancel', $receipt);
    }

    public function rules(): array
    {
        return [
            'reason'       => ['nullable', 'string', 'max:500'],
            'cancelled_at' => ['prohibited'],
            'cancelled_by' => ['prohibited'],
            'status'       => ['prohibited'],
        ];
    }
}
