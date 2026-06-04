<?php

namespace Modules\Operations\Zoning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Zoning\Enums\ZonePriorityEnum;
use Modules\Operations\Zoning\Enums\ZoneTypeEnum;
use Modules\Operations\Zoning\Models\Zone;
use Shared\Services\CurrentPropertyService;

class UpdateZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $zone = Zone::find($this->route('zone'));

        return $zone && $this->user()->can('update', $zone);
    }

    public function rules(): array
    {
        $zoneId     = $this->route('zone');
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'zone_code'   => ['sometimes', 'string', 'max:50', "unique:zones,zone_code,{$zoneId},id,property_id,{$propertyId},deleted_at,NULL"],
            'zone_name'   => ['sometimes', 'string', 'max:255'],
            'zone_type'   => ['sometimes', Rule::enum(ZoneTypeEnum::class)],
            'description' => ['nullable', 'string'],
            'priority'    => ['nullable', Rule::enum(ZonePriorityEnum::class)],
            'status'      => ['prohibited'],
        ];
    }
}
