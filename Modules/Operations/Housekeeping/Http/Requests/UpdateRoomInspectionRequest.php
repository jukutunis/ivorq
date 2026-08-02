<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Enums\InspectionTypeEnum;
use Modules\Operations\Housekeeping\Models\RoomInspection;

class UpdateRoomInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $inspection = RoomInspection::find($this->route('inspection'));

        return $inspection && $this->user()->can('update', $inspection);
    }

    public function rules(): array
    {
        return [
            'inspector_id'        => ['prohibited'],
            'supervisor_id'       => ['prohibited'],
            'inspection_type'     => ['prohibited'],
            'inspection_severity' => ['prohibited'],
            'remarks'             => ['nullable', 'string'],
            'status'              => ['prohibited'],
            'is_passed'           => ['prohibited'],
            'inspected_at'        => ['prohibited'],
            'cleaning_task_id'    => ['prohibited'],
            'room_id'             => ['prohibited'],
            'property_id'         => ['prohibited'],
            'company_id'          => ['prohibited'],
        ];
    }
}
