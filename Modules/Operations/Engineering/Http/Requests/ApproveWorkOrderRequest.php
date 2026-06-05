<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Engineering\Models\WorkOrder;

class ApproveWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workOrder = WorkOrder::find($this->route('wo'));

        return $workOrder && $this->user()->can('approve', $workOrder);
    }

    public function rules(): array
    {
        return [
            // approved_by is always derived from auth() in WorkOrderService::approve().
            // No body fields are accepted from the client.
            'approved_by' => ['prohibited'],
        ];
    }
}
