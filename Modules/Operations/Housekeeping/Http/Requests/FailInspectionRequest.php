<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Shared\Services\CurrentPropertyService;

class FailInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($propertyId);
        $inspection = RoomInspection::withoutGlobalScopes()
            ->whereKey($this->route('inspection'))
            ->where('property_id', $propertyId)
            ->first();

        return $inspection
            && $this->user()->can('conduct', $inspection)
            && $this->user()->can(HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION);
    }

    public function rules(): array
    {
        return [
            'remarks' => ['required', 'string', 'max:1000'],
            'inspection_severity' => ['nullable', Rule::enum(InspectionSeverityEnum::class)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_diff(array_keys($this->all()), ['remarks', 'inspection_severity', '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This lifecycle authority parameter is not accepted.');
            }
        });
    }
}
