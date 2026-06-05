<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Engineering\Enums\WorkOrderStatusEnum;
use Modules\Operations\Engineering\Models\WorkOrder;

class ChangeWorkOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workOrder = WorkOrder::find($this->route('wo'));

        return $workOrder && $this->user()->can('changeStatus', $workOrder);
    }

    public function rules(): array
    {
        return [
            'status'  => ['required', Rule::enum(WorkOrderStatusEnum::class)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
