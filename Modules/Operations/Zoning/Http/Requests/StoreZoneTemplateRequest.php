<?php

namespace Modules\Operations\Zoning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Zoning\Enums\ZonePriorityEnum;
use Modules\Operations\Zoning\Enums\ZoneTypeEnum;
use Modules\Operations\Zoning\Models\ZoneTemplate;
use Shared\Services\CurrentPropertyService;

class StoreZoneTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ZoneTemplate::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'template_name'    => ['required', 'string', 'max:255', "unique:zone_templates,template_name,NULL,id,property_id,{$propertyId},deleted_at,NULL"],
            'zone_type'        => ['required', Rule::enum(ZoneTypeEnum::class)],
            'default_priority' => ['nullable', Rule::enum(ZonePriorityEnum::class)],
            'description'      => ['nullable', 'string'],
        ];
    }
}
