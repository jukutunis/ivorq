<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Engineering\Models\EngineeringChecklist;
use Shared\Services\CurrentPropertyService;

class ReorderEngineeringChecklistItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $checklist = EngineeringChecklist::find($this->route('checklist'));

        return $checklist && $this->user()->can('update', $checklist);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'items'   => ['required', 'array'],
            // Each ID must belong to a checklist item in the current property
            'items.*' => ['string',
                Rule::exists('engineering_checklist_items', 'id')->where('property_id', $propertyId),
            ],
        ];
    }
}
