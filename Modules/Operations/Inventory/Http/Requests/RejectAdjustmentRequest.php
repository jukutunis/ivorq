<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Inventory\Models\InventoryAdjustment;

class RejectAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $adjustment = InventoryAdjustment::find($this->route('adjustment'));

        return $adjustment && $this->user()->can('reject', $adjustment);
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:500'],
            'status'           => ['prohibited'],
            'rejected_by'      => ['prohibited'],
            'rejected_at'      => ['prohibited'],
        ];
    }
}
