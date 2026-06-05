<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\PMS\Enums\GuestTypeEnum;
use Modules\Operations\PMS\Models\Guest;

class UpdateGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $guest = Guest::find($this->route('guest'));

        return $guest && $this->user()->can('update', $guest);
    }

    public function rules(): array
    {
        return [
            'full_name'   => ['sometimes', 'string', 'max:255'],
            'email'       => ['nullable', 'email', 'max:255'],
            'phone'       => ['nullable', 'string', 'max:50'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'id_type'     => ['nullable', 'string', 'max:50'],
            'id_number'   => ['nullable', 'string', 'max:100'],
            'guest_type'  => ['sometimes', Rule::enum(GuestTypeEnum::class)],
            'vip_level'   => ['nullable', 'integer', 'min:1', 'max:5'],
            'notes'       => ['nullable', 'string'],

            // Immutable after creation
            'guest_code'  => ['prohibited'],
            // Audit columns are server-managed
            'created_by'  => ['prohibited'],
            'updated_by'  => ['prohibited'],
        ];
    }
}
