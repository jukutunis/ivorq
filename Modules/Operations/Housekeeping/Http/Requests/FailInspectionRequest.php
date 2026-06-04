<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Models\RoomInspection;

class FailInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $inspection = RoomInspection::find($this->route('inspection'));

        return $inspection && $this->user()->can('conduct', $inspection);
    }

    public function rules(): array
    {
        return [
            'remarks'             => ['nullable', 'string', 'max:1000'],
            'inspection_severity' => ['nullable', Rule::enum(InspectionSeverityEnum::class)],
        ];
    }
}
