<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;
use Shared\Services\CurrentPropertyService;

class ReorderChecklistItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $checklist = CleaningChecklist::find($this->route('checklist'));

        return $checklist && $this->user()->can('update', $checklist);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'items'   => ['required', 'array'],
            'items.*' => ['string', Rule::exists('checklist_items', 'id')->where('property_id', $propertyId)],
        ];
    }
}
