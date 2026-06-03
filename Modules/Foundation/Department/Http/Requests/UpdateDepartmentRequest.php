<?php

namespace Modules\Foundation\Department\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $department = \Modules\Foundation\Department\Models\Department::find($this->route('department'));

        return $department && $this->user()->can('update', $department);
    }

    public function rules(): array
    {
        $departmentId = $this->route('department');
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getId();

        return [
            'name'        => ['sometimes', 'string', 'max:255'],
            'code'        => ['sometimes', 'string', 'max:20', "unique:departments,code,{$departmentId},id,property_id,{$propertyId}"],
            'description' => ['nullable', 'string'],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }
}
