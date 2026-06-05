<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Engineering\Models\EngineeringChecklist;

class StoreEngineeringChecklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $checklist = EngineeringChecklist::find(
            $this->route('checklist') ?? $this->input('engineering_checklist_id')
        );

        return $checklist && $this->user()->can('update', $checklist);
    }

    public function rules(): array
    {
        return [
            'item_text'   => ['required', 'string', 'max:500'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'is_required' => ['nullable', 'boolean'],
        ];
    }
}
