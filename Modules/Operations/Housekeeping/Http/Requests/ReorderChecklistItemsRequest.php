<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;

class ReorderChecklistItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $checklist = CleaningChecklist::find($this->route('checklist'));

        return $checklist && $this->user()->can('update', $checklist);
    }

    public function rules(): array
    {
        return [
            'items'   => ['required', 'array'],
            'items.*' => ['string', 'exists:checklist_items,id'],
        ];
    }
}
