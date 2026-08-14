<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Shared\Services\CurrentPropertyService;

class ConfirmInspectionClaimReassignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($propertyId);
        if ($this->header('X-Property-ID') !== $propertyId) {
            return false;
        }
        $inspection = RoomInspection::withoutGlobalScopes()
            ->whereKey($this->route('inspection'))
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->first();

        return $inspection !== null && $this->user()?->can('reassignClaim', $inspection) === true;
    }

    public function rules(): array
    {
        return [
            'replacement_inspector_id' => ['required', 'string', 'size:26'],
            'reason' => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:160', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,159}\z/'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $allowed = ['replacement_inspector_id', 'reason', 'idempotency_key', 'password', '_token', '_method'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add('request', "The {$field} authority parameter is not accepted.");
            }
        });
    }
}
