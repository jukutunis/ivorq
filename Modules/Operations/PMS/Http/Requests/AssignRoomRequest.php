<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;

class AssignRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = Reservation::find($this->route('reservation'));

        return $reservation && $this->user()->can('update', $reservation);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'room_id' => ['required', 'string', 'size:26',
                Rule::exists('rooms', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
        ];
    }
}
