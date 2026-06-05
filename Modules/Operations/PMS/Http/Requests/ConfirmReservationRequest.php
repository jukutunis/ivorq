<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\PMS\Models\Reservation;

class ConfirmReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = Reservation::find($this->route('reservation'));

        return $reservation && $this->user()->can('changeStatus', $reservation);
    }

    public function rules(): array
    {
        return [];
    }
}
