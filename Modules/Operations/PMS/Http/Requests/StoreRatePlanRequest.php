<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\PMS\Enums\RatePlanTypeEnum;
use Modules\Operations\PMS\Models\RatePlan;
use Shared\Services\CurrentPropertyService;

class StoreRatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RatePlan::class);
    }

    public function rules(): array
    {
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'rate_code'   => ['required', 'string', 'max:20',
                "unique:rate_plans,rate_code,NULL,id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'rate_name'   => ['required', 'string', 'max:255'],
            'plan_type'   => ['required', Rule::enum(RatePlanTypeEnum::class)],
            'base_rate'   => ['required', 'numeric', 'min:0'],
            'currency'    => ['nullable', 'string', 'size:3'],
            'is_active'   => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],

            // Audit columns are server-managed via HasAuditColumns
            'created_by'  => ['prohibited'],
            'updated_by'  => ['prohibited'],
        ];
    }
}
