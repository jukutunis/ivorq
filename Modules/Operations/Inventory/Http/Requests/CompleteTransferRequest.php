<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Inventory\Models\InventoryTransfer;

class CompleteTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transfer = InventoryTransfer::find($this->route('transfer'));

        return $transfer && $this->user()->can('complete', $transfer);
    }

    public function rules(): array
    {
        return [
            // Completing is a pure state transition — no body accepted.
            'status'       => ['prohibited'],
            'completed_by' => ['prohibited'],
            'completed_at' => ['prohibited'],
        ];
    }
}
