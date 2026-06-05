<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Inventory\Models\InventoryAdjustment;

class ApproveAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $adjustment = InventoryAdjustment::find($this->route('adjustment'));

        return $adjustment && $this->user()->can('approve', $adjustment);
    }

    public function rules(): array
    {
        return [
            // Approval is a pure state transition — no body accepted.
            // approved_by is always derived from auth() in AdjustmentService::approve().
            'status'      => ['prohibited'],
            'approved_by' => ['prohibited'],
            'approved_at' => ['prohibited'],
        ];
    }
}
