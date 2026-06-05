<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\PMS\Models\Reservation;

class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = Reservation::find($this->route('reservation'));

        return $reservation && $this->user()->can('checkIn', $reservation);
    }

    public function rules(): array
    {
        return [];
    }
}
