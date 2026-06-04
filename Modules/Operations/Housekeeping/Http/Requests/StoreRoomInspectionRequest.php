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
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'room_id'          => ['required', 'string', Rule::exists('rooms', 'id')->where('property_id', $propertyId)->whereNull('deleted_at')],
            'cleaning_task_id' => ['nullable', 'string', Rule::exists('cleaning_tasks', 'id')->where('property_id', $propertyId)->whereNull('deleted_at')],
            'inspector_id'     => ['nullable', 'string', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'inspection_type'  => ['required', Rule::enum(InspectionTypeEnum::class)],
            'remarks'          => ['nullable', 'string'],
        ];
    }
}
