<?php

namespace Modules\Foundation\Department\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Shared\Services\CurrentPropertyService;

class StorePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \Modules\Foundation\Department\Models\Department::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'department_id' => ['required', 'string', Rule::exists('departments', 'id')->where('property_id', $propertyId)->whereNull('deleted_at')],
            'name'          => ['required', 'string', 'max:255'],
            'code'          => ['required', 'string', 'max:20'],
            'level'         => ['required', 'integer', 'min:1', 'max:10'],
            'description'   => ['nullable', 'string'],
            'is_active'     => ['sometimes', 'boolean'],
        ];
    }
}
