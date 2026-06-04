<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\RoomTypeEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Shared\Services\CurrentPropertyService;

class UpdateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = Room::find($this->route('room'));

        return $room && $this->user()->can('update', $room);
    }

    public function rules(): array
    {
        $roomId     = $this->route('room');
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'room_number' => ['sometimes', 'string', 'max:20',
                "unique:rooms,room_number,{$roomId},id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'room_name'          => ['nullable', 'string', 'max:255'],
            'room_type'          => ['sometimes', Rule::enum(RoomTypeEnum::class)],
            'zone_id'            => ['nullable', 'string', Rule::exists('zones', 'id')->where('property_id', $propertyId)->whereNull('deleted_at')],
            'floor'              => ['nullable', 'string', 'max:10'],
            'building'           => ['nullable', 'string', 'max:100'],
            'is_active'          => ['nullable', 'boolean'],
            'notes'              => ['nullable', 'string'],
            'cleanliness_status' => ['prohibited'],
            'occupancy_status'   => ['prohibited'],
        ];
    }
}
