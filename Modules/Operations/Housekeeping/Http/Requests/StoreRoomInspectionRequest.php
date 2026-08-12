<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\InspectionTypeEnum;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Shared\Services\CurrentPropertyService;

class StoreRoomInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RoomInspection::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        return [
            'room_id'          => ['required', 'string', Rule::exists('rooms', 'id')->where('property_id', $propertyId)->whereNull('deleted_at')],
            'cleaning_task_id' => ['nullable', 'string', Rule::exists('cleaning_tasks', 'id')->where('property_id', $propertyId)->whereNull('deleted_at')],
            'inspection_type'  => ['required', Rule::enum(InspectionTypeEnum::class), Rule::notIn(['post_cleaning'])],
            'remarks'          => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $accepted = ['room_id', 'cleaning_task_id', 'inspection_type', 'remarks', '_token', '_method'];
            foreach (array_diff(array_keys($this->all()), $accepted) as $field) {
                $validator->errors()->add('request', "The {$field} authority parameter is not accepted.");
            }
        });
    }
}
