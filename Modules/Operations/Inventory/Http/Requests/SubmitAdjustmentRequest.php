<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Inventory\Models\InventoryAdjustment;

class SubmitAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $adjustment = InventoryAdjustment::find($this->route('adjustment'));

        return $adjustment && $this->user()->can('submit', $adjustment);
    }

    public function rules(): array
    {
        return [
            // Submitting is a pure state transition — no body accepted.
            'status'       => ['prohibited'],
            'submitted_by' => ['prohibited'],
            'submitted_at' => ['prohibited'],
        ];
    }
}
