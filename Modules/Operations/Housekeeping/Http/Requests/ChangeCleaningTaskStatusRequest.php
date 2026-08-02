<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Shared\Services\CurrentPropertyService;

class ChangeCleaningTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($propertyId);
        $task = CleaningTask::withoutGlobalScopes()
            ->whereKey($this->route('task'))
            ->where('property_id', $propertyId)
            ->first();
        if (! $task || ! $this->user()->can('changeStatus', $task)) {
            return false;
        }

        return match ((string) $this->input('status')) {
            TaskStatusEnum::InProgress->value => $this->user()->can(HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION),
            TaskStatusEnum::Completed->value => $this->user()->can(HousekeepingRoomReadinessTransitionService::SUBMIT_INSPECTION_PERMISSION),
            default => true,
        };
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TaskStatusEnum::class)],
            'remarks' => ['required_if:status,completed', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (array_diff(array_keys($this->all()), ['status', 'remarks', '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This lifecycle authority parameter is not accepted.');
            }
        });
    }
}
