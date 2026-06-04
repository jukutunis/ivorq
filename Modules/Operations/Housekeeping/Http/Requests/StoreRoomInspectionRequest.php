<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\InspectionTypeEnum;
use Modules\Operations\Housekeeping\Models\RoomInspection;

class StoreRoomInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RoomInspection::class);
    }

    public function rules(): array
    {
        return [
            'room_id'          => ['required', 'string', 'exists:rooms,id'],
            'cleaning_task_id' => ['nullable', 'string', 'exists:cleaning_tasks,id'],
            'inspector_id'     => ['nullable', 'string', 'exists:users,id'],
            'inspection_type'  => ['required', Rule::enum(InspectionTypeEnum::class)],
            'remarks'          => ['nullable', 'string'],
        ];
    }
}
