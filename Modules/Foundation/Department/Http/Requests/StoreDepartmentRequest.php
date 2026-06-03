<?php

namespace Modules\Foundation\Department\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \Modules\Foundation\Department\Models\Department::class);
    }

    public function rules(): array
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getId();

        return [
            'name'        => ['required', 'string', 'max:255'],
            'code'        => ['required', 'string', 'max:20', "unique:departments,code,NULL,id,property_id,{$propertyId}"],
            'description' => ['nullable', 'string'],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }
}
