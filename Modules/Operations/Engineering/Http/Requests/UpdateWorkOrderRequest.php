<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Engineering\Enums\WorkOrderTypeEnum;
use Modules\Operations\Engineering\Models\WorkOrder;
use Shared\Services\CurrentPropertyService;

class UpdateWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workOrder = WorkOrder::find($this->route('wo'));

        return $workOrder && $this->user()->can('update', $workOrder);
    }

    public function rules(): array
    {
        $woId       = $this->route('wo');
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'work_order_number'    => ['sometimes', 'string', 'max:20',
                "unique:work_orders,work_order_number,{$woId},id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'title'                => ['sometimes', 'string', 'max:255'],
            'description'          => ['nullable', 'string'],
            'work_order_type'      => ['sometimes', Rule::enum(WorkOrderTypeEnum::class)],
            'priority'             => ['nullable', 'integer', 'min:1', 'max:4'],
            'location_type'        => ['nullable', 'string', Rule::in(['room', 'zone', 'facility', 'general'])],
            'room_id'              => ['nullable', 'string',
                Rule::exists('rooms', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'zone_id'              => ['nullable', 'string',
                Rule::exists('zones', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'location_description' => ['nullable', 'string', 'max:255'],
            'asset_description'    => ['nullable', 'string', 'max:255'],
            'sla_hours'            => ['nullable', 'numeric', 'min:0.5', 'max:720'],
            'estimated_hours'      => ['nullable', 'numeric', 'min:0.1'],
            'due_date'             => ['nullable', 'date'],

            // Status lifecycle is controlled by changeStatus() — not editable here
            'status'           => ['prohibited'],
            'started_at'       => ['prohibited'],
            'completed_at'     => ['prohibited'],
            'cancelled_at'     => ['prohibited'],
            'approved_at'      => ['prohibited'],
            'completed_by'     => ['prohibited'],
            'cancelled_by'     => ['prohibited'],
            'approved_by'      => ['prohibited'],
        ];
    }
}
