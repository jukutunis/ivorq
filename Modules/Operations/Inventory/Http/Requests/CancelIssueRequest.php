<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Inventory\Models\InventoryIssue;

class CancelIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $issue = InventoryIssue::find($this->route('issue'));

        return $issue && $this->user()->can('cancel', $issue);
    }

    public function rules(): array
    {
        return [
            'reason'       => ['nullable', 'string', 'max:500'],
            'cancelled_at' => ['prohibited'],
            'cancelled_by' => ['prohibited'],
            'status'       => ['prohibited'],
        ];
    }
}
