<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;

class UpdateChecklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $checklist = CleaningChecklist::find($this->route('checklist'));

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
