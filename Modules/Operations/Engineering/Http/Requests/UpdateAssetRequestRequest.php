<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Engineering\Models\AssetRequest;
use Shared\Services\CurrentPropertyService;

class UpdateAssetRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = AssetRequest::find($this->route('req'));

        return $request && $this->user()->can('update', $request);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'title'         => ['sometimes', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'work_order_id' => ['nullable', 'string',
                Rule::exists('work_orders', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],
            'priority'      => ['nullable', 'integer', 'min:1', 'max:4'],
            'required_by'   => ['nullable', 'date'],
            'department_id' => ['nullable', 'string',
                Rule::exists('departments', 'id')->where('property_id', $propertyId)->whereNull('deleted_at'),
            ],

            'status'        => ['prohibited'],
            'approved_by'   => ['prohibited'],
            'approved_at'   => ['prohibited'],
            'rejected_by'   => ['prohibited'],
            'rejected_at'   => ['prohibited'],
            'fulfilled_by'  => ['prohibited'],
            'fulfilled_at'  => ['prohibited'],
        ];
    }
}
