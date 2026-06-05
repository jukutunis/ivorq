<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Inventory\Models\InventoryAdjustment;

class CancelAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $adjustment = InventoryAdjustment::find($this->route('adjustment'));

        return $adjustment && $this->user()->can('cancel', $adjustment);
    }

    public function rules(): array
    {
        return [
            'reason'           => ['nullable', 'string', 'max:500'],
            'status'           => ['prohibited'],
            'rejected_by'      => ['prohibited'],
            'rejected_at'      => ['prohibited'],
            'rejection_reason' => ['prohibited'],
        ];
    }
}
