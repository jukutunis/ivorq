<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Inventory\Models\InventoryTransfer;

class CancelTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transfer = InventoryTransfer::find($this->route('transfer'));

        return $transfer && $this->user()->can('cancel', $transfer);
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
