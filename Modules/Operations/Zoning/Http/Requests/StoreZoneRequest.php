<?php

namespace Modules\Operations\Zoning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Zoning\Enums\ZonePriorityEnum;
use Modules\Operations\Zoning\Enums\ZoneTypeEnum;
use Modules\Operations\Zoning\Models\Zone;
use Shared\Services\CurrentPropertyService;

class StoreZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Zone::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'zone_code'   => ['required', 'string', 'max:50', "unique:zones,zone_code,NULL,id,property_id,{$propertyId},deleted_at,NULL"],
            'zone_name'   => ['required', 'string', 'max:255'],
            'zone_type'   => ['required', Rule::enum(ZoneTypeEnum::class)],
            'description' => ['nullable', 'string'],
            'priority'    => ['nullable', Rule::enum(ZonePriorityEnum::class)],
        ];
    }
}
