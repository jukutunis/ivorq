<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\PMS\Enums\RoomBlockReasonEnum;
use Modules\Operations\PMS\Enums\RoomBlockTypeEnum;
use Modules\Operations\PMS\Models\RoomBlock;
use Shared\Services\CurrentPropertyService;

class StoreRoomBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RoomBlock::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'room_id'    => ['required', 'string', 'size:26',
                Rule::exists('rooms', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'block_type' => ['required', Rule::enum(RoomBlockTypeEnum::class)],
            'reason'     => ['nullable', Rule::enum(RoomBlockReasonEnum::class)],
            'notes'      => ['nullable', 'string'],
            'start_at'   => ['required', 'date'],
            'end_at'     => ['nullable', 'date', 'after:start_at'],

            // Release tracking is written by RoomBlockService::release() only
            'released_at' => ['prohibited'],
            'released_by' => ['prohibited'],
            // Status transitions use dedicated endpoints
            'status'      => ['prohibited'],
        ];
    }
}
