<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Engineering\Models\EngineeringChecklist;

class UpdateEngineeringChecklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $checklist = EngineeringChecklist::find($this->route('checklist'));

        return $checklist && $this->user()->can('update', $checklist);
    }

    public function rules(): array
    {
        return [
            'item_text'   => ['sometimes', 'string', 'max:500'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'is_required' => ['nullable', 'boolean'],
        ];
    }
}
