<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\PMS\Enums\RoomBlockReasonEnum;
use Modules\Operations\PMS\Enums\RoomBlockTypeEnum;
use Modules\Operations\PMS\Models\RoomBlock;
use Shared\Services\CurrentPropertyService;

class UpdateRoomBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        $block = RoomBlock::find($this->route('room_block'));

        return $block && $this->user()->can('update', $block);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'room_id'    => ['sometimes', 'string', 'size:26',
                Rule::exists('rooms', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'block_type' => ['sometimes', Rule::enum(RoomBlockTypeEnum::class)],
            'reason'     => ['nullable', Rule::enum(RoomBlockReasonEnum::class)],
            'notes'      => ['nullable', 'string'],
            'start_at'   => ['sometimes', 'date'],
            'end_at'     => ['nullable', 'date'],

            // Release tracking is server-controlled
            'released_at' => ['prohibited'],
            'released_by' => ['prohibited'],
            // Status transitions use dedicated endpoints
            'status'      => ['prohibited'],
        ];
    }
}
