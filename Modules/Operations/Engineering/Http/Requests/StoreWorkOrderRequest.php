<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Engineering\Enums\WorkOrderTypeEnum;
use Modules\Operations\Engineering\Models\WorkOrder;
use Shared\Services\CurrentPropertyService;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', WorkOrder::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'work_order_number'  => ['required', 'string', 'max:20',
                "unique:work_orders,work_order_number,NULL,id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string'],
            'work_order_type'    => ['required', Rule::enum(WorkOrderTypeEnum::class)],
            'priority'           => ['nullable', 'integer', 'min:1', 'max:4'],
            'location_type'      => ['nullable', 'string', Rule::in(['room', 'zone', 'facility', 'general'])],
            'room_id'            => ['nullable', 'string',
                Rule::exists('rooms', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'zone_id'            => ['nullable', 'string',
                Rule::exists('zones', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'location_description' => ['nullable', 'string', 'max:255'],
            'asset_description'    => ['nullable', 'string', 'max:255'],
            'sla_hours'            => ['nullable', 'numeric', 'min:0.5', 'max:720'],
            'estimated_hours'      => ['nullable', 'numeric', 'min:0.1'],
            'due_date'             => ['nullable', 'date'],

            // Actor and status fields are server-controlled — reject if supplied
            'status'       => ['prohibited'],
            'completed_by' => ['prohibited'],
            'cancelled_by' => ['prohibited'],
            'approved_by'  => ['prohibited'],
        ];
    }
}
