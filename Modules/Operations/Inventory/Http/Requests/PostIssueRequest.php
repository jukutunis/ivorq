<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Inventory\Models\InventoryIssue;

class PostIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $issue = InventoryIssue::find($this->route('issue'));

        return $issue && $this->user()->can('post', $issue);
    }

    public function rules(): array
    {
        return [
            // Posting is a pure state transition — no body accepted.
            'status'    => ['prohibited'],
            'posted_at' => ['prohibited'],
            'posted_by' => ['prohibited'],
        ];
    }
}
