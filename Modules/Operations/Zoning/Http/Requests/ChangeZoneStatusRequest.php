<?php

namespace Modules\Operations\Zoning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Zoning\Enums\ZoneStatusEnum;
use Modules\Operations\Zoning\Models\Zone;

class ChangeZoneStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $zone = Zone::find($this->route('zone'));

        if (! $zone) {
            return false;
        }

        if ($this->input('status') === ZoneStatusEnum::Archived->value) {
            return $this->user()->can('archive', $zone);
        }

        return $this->user()->can('changeStatus', $zone);
    }

    public function rules(): array
    {
        return [
            'status'  => ['required', Rule::enum(ZoneStatusEnum::class)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
