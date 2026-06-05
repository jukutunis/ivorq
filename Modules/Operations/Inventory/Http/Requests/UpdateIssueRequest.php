<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Shared\Services\CurrentPropertyService;

class UpdateIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $issue = InventoryIssue::find($this->route('issue'));

        return $issue && $this->user()->can('update', $issue);
    }

    public function rules(): array
    {
        $issueId    = $this->route('issue');
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'issue_number'   => ['sometimes', 'string', 'max:30',
                "unique:inventory_issues,issue_number,{$issueId},id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'issued_at'      => ['nullable', 'date'],
            'department_id'  => ['nullable', 'string', 'size:26',
                Rule::exists('departments', 'id')
                    ->where('property_id', $propertyId)
                    ->whereNull('deleted_at'),
            ],
            'issued_to_type' => ['nullable', 'string', 'max:100'],
            'issued_to_id'   => ['nullable', 'string', 'size:26'],
            'remarks'        => ['nullable', 'string', 'max:500'],

            // Lines optional on update
            'lines'               => ['sometimes', 'array', 'min:1'],
            'lines.*.item_id'     => ['sometimes', 'string', 'size:26',
                Rule::exists('inventory_items', 'id')
                    ->where('property_id', $propertyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'lines.*.location_id' => ['sometimes', 'string', 'size:26',
                Rule::exists('inventory_locations', 'id')
                    ->where('property_id', $propertyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'lines.*.quantity'    => ['sometimes', 'numeric', 'min:0.001'],
            'lines.*.remarks'     => ['nullable', 'string', 'max:500'],

            // Server-controlled lifecycle
            'status'       => ['prohibited'],
            'posted_at'    => ['prohibited'],
            'posted_by'    => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'cancelled_by' => ['prohibited'],
        ];
    }
}
