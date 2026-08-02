<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Shared\Services\CurrentPropertyService;

class ConductInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($propertyId);
        $inspection = RoomInspection::withoutGlobalScopes()
            ->whereKey($this->route('inspection'))
            ->where('property_id', $propertyId)
            ->first();

        return $inspection && $this->user()->can('conduct', $inspection);
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_diff(array_keys($this->all()), ['_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This lifecycle authority parameter is not accepted.');
            }
        });
    }
}
