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
            'inspector_id'        => ['nullable', 'string', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'inspection_type'     => ['sometimes', Rule::enum(InspectionTypeEnum::class)],
            'inspection_severity' => ['nullable', Rule::enum(InspectionSeverityEnum::class)],
            'remarks'             => ['nullable', 'string'],
            'status'              => ['prohibited'],
        ];
    }
}
