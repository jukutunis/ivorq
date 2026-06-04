<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Models\Room;

class ChangeRoomCleanlinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = Room::find($this->route('room'));

        return $room && $this->user()->can('changeStatus', $room);
    }

    public function rules(): array
    {
        return [
            'cleanliness_status' => ['required', Rule::enum(RoomCleanlinessStatusEnum::class)],
            'remarks'            => ['nullable', 'string', 'max:1000'],
        ];
    }
}
