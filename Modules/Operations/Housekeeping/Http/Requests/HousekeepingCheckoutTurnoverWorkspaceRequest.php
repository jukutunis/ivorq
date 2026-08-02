<?php

namespace Modules\Operations\Housekeeping\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;

class HousekeepingCheckoutTurnoverWorkspaceRequest extends FormRequest
{
    public const STATES = [
        'review_required',
        'completed',
        'delivery_confirmation_pending',
        'active_claim',
        'ready',
        'retry_wait',
        'scheduled',
    ];

    public const SORTS = [
        'occurred_at',
        'available_at',
        'business_date',
        'room_number',
        'attempts',
        'operational_state',
        'task_status',
        'delivered_at',
    ];

    private const PARAMETERS = [
        'state',
        'search',
        'business_date',
        'task_status',
        'sort',
        'direction',
        'page',
        'per_page',
        'selected',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'state' => ['nullable', 'string', Rule::in(self::STATES)],
            'search' => ['nullable', 'string', 'max:120'],
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'task_status' => [
                'nullable',
                'string',
                Rule::in(array_column(TaskStatusEnum::cases(), 'value')),
            ],
            'sort' => ['nullable', 'string', Rule::in(self::SORTS)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'selected' => ['nullable', 'ulid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $unknown = array_diff(array_keys($this->query()), self::PARAMETERS);

        $validator->after(function (Validator $validator) use ($unknown): void {
            foreach ($unknown as $parameter) {
                $validator->errors()->add($parameter, 'This query parameter is not allowed.');
            }
        });
    }
}
