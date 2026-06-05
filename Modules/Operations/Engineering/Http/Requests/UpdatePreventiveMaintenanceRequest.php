<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Engineering\Enums\PmFrequencyEnum;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;
use Shared\Services\CurrentPropertyService;

class UpdatePreventiveMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pm = PreventiveMaintenance::find($this->route('pm'));

        return $pm && $this->user()->can('update', $pm);
    }

    public function rules(): array
    {
        $pmId       = $this->route('pm');
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'pm_code'          => ['sometimes', 'string', 'max:20',
                "unique:preventive_maintenances,pm_code,{$pmId},id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'title'            => ['sometimes', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'frequency'        => ['sometimes', Rule::enum(PmFrequencyEnum::class)],
            'frequency_days'   => ['nullable', 'integer', 'min:1', 'max:365'],
            'room_id'          => ['nullable', 'string',
                Rule::exists('rooms', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'zone_id'          => ['nullable', 'string',
                Rule::exists('zones', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'asset_description' => ['nullable', 'string', 'max:255'],
            'estimated_hours'   => ['nullable', 'numeric', 'min:0.1'],
            'department_id'     => ['nullable', 'string',
                Rule::exists('departments', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],

            'status'      => ['prohibited'],
            'last_run_at' => ['prohibited'],
            'next_due_at' => ['prohibited'],
        ];
    }
}
