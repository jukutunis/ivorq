<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\PMS\Models\RoomBlock;

class ReleaseRoomBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        $block = RoomBlock::find($this->route('room_block'));

        return $block && $this->user()->can('changeStatus', $block);
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
