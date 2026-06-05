<?php

namespace Modules\Operations\PMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\PMS\Enums\RatePlanTypeEnum;
use Modules\Operations\PMS\Models\RatePlan;
use Shared\Services\CurrentPropertyService;

class UpdateRatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ratePlan = RatePlan::find($this->route('rate_plan'));

        return $ratePlan && $this->user()->can('update', $ratePlan);
    }

    public function rules(): array
    {
        $ratePlanId = $this->route('rate_plan');
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'rate_code'   => ['sometimes', 'string', 'max:20',
                "unique:rate_plans,rate_code,{$ratePlanId},id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'rate_name'   => ['sometimes', 'string', 'max:255'],
            'plan_type'   => ['sometimes', Rule::enum(RatePlanTypeEnum::class)],
            'base_rate'   => ['sometimes', 'numeric', 'min:0'],
            'currency'    => ['nullable', 'string', 'size:3'],
            'is_active'   => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],

            // Audit columns are server-managed
            'created_by'  => ['prohibited'],
            'updated_by'  => ['prohibited'],
        ];
    }
}
