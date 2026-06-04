<?php

namespace Modules\Operations\Zoning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Zoning\Enums\ZonePriorityEnum;
use Modules\Operations\Zoning\Enums\ZoneTypeEnum;
use Modules\Operations\Zoning\Models\ZoneTemplate;
use Shared\Services\CurrentPropertyService;

class UpdateZoneTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = ZoneTemplate::find($this->route('template'));

        return $template && $this->user()->can('update', $template);
    }

    public function rules(): array
    {
        $templateId = $this->route('template');
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'template_name'    => ['sometimes', 'string', 'max:255', "unique:zone_templates,template_name,{$templateId},id,property_id,{$propertyId},deleted_at,NULL"],
            'zone_type'        => ['sometimes', Rule::enum(ZoneTypeEnum::class)],
            'default_priority' => ['nullable', Rule::enum(ZonePriorityEnum::class)],
            'description'      => ['nullable', 'string'],
        ];
    }
}
