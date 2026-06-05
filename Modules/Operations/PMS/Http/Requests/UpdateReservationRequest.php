<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\RoomTypeEnum;
use Modules\Operations\PMS\Enums\ReservationSourceEnum;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;

class UpdateReservationRequest extends FormRequest
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
            'primary_guest_id'   => ['sometimes', 'string', 'size:26',
                Rule::exists('guests', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'rate_plan_id'       => ['nullable', 'string', 'size:26',
                Rule::exists('rate_plans', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'arrival_date'       => ['sometimes', 'date'],
            'departure_date'     => ['sometimes', 'date'],
            'nights'             => ['nullable', 'integer', 'min:1'],
            'adults'             => ['nullable', 'integer', 'min:1', 'max:20'],
            'children'           => ['nullable', 'integer', 'min:0', 'max:20'],
            'reservation_source' => ['sometimes', Rule::enum(ReservationSourceEnum::class)],
            'reserved_room_type' => ['sometimes', Rule::enum(RoomTypeEnum::class)],
            'assigned_room_id'   => ['nullable', 'string', 'size:26',
                Rule::exists('rooms', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'remarks'            => ['nullable', 'string'],

            // Immutable
            'reservation_number' => ['prohibited'],
            // Status transitions use dedicated endpoints
            'status'             => ['prohibited'],
            // Check-in/out timestamps live on Stay
            'check_in_at'        => ['prohibited'],
            'check_out_at'       => ['prohibited'],
            // Financial totals belong to Folio
            'total_charges'      => ['prohibited'],
            'total_payments'     => ['prohibited'],
            'balance'            => ['prohibited'],
        ];
    }
}
