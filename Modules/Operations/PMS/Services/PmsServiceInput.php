<?php

namespace Modules\Operations\PMS\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Housekeeping\Enums\RoomTypeEnum;
use Modules\Operations\PMS\Enums\GuestTypeEnum;
use Modules\Operations\PMS\Enums\RatePlanTypeEnum;
use Modules\Operations\PMS\Enums\ReservationSourceEnum;
use Modules\Operations\PMS\Enums\RoomBlockReasonEnum;
use Modules\Operations\PMS\Enums\RoomBlockTypeEnum;

/** Business input validation shared by HTTP and direct PMS service calls. */
class PmsServiceInput
{
    public function reservation(array $data, bool $create): array
    {
        foreach (['arrival_date', 'departure_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->calendarDate($data[$field], $field);
            }
        }
        $required = $create ? 'required' : 'sometimes';
        $this->check($data, [
            'primary_guest_id' => [$required, 'string', 'size:26'],
            'rate_plan_id' => ['nullable', 'string', 'size:26'],
            'arrival_date' => [$required, 'date_format:Y-m-d'],
            'departure_date' => [$required, 'date_format:Y-m-d'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'reservation_source' => [$create ? 'nullable' : 'sometimes', Rule::enum(ReservationSourceEnum::class)],
            'reserved_room_type' => [$required, Rule::enum(RoomTypeEnum::class)],
            'remarks' => ['nullable', 'string'],
        ]);
        return $data;
    }

    public function guest(array $data, bool $create): array
    {
        $this->check($data, [
            'full_name' => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'id_type' => ['nullable', 'string', 'max:50'],
            'id_number' => ['nullable', 'string', 'max:100'],
            'guest_type' => [$create ? 'nullable' : 'sometimes', Rule::enum(GuestTypeEnum::class)],
            'vip_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'notes' => ['nullable', 'string'],
        ]);
        return $data;
    }

    public function ratePlan(array $data, bool $create, string $propertyId, ?string $ratePlanId = null): array
    {
        $required = $create ? 'required' : 'sometimes';
        $this->check($data, [
            'rate_code' => [$required, 'string', 'max:20',
                Rule::unique('rate_plans', 'rate_code')->where('property_id', $propertyId)
                    ->whereNull('deleted_at')->ignore($ratePlanId)],
            'rate_name' => [$required, 'string', 'max:255'],
            'plan_type' => [$required, Rule::enum(RatePlanTypeEnum::class)],
            'base_rate' => [$required, 'numeric', 'between:0,99999999.99', 'decimal:0,2'],
            'currency' => [$create ? 'nullable' : 'sometimes', 'string', 'size:3'],
            'is_active' => [$create ? 'nullable' : 'sometimes', 'boolean'],
            'description' => ['nullable', 'string'],
        ]);
        return $data;
    }

    public function roomBlock(array $data, bool $create, ?string $existingStart = null, ?string $existingEnd = null): array
    {
        if (array_diff(array_keys($data), ['room_id', 'block_type', 'reason', 'notes', 'start_at', 'end_at']) !== []) {
            throw ValidationException::withMessages(['room_block' => ['Unsupported room block fields.']]);
        }
        foreach (['start_at', 'end_at'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = $this->dateTime($data[$field], $field);
            }
        }
        $required = $create ? 'required' : 'sometimes';
        $this->check($data, [
            'room_id' => [$required, 'string', 'size:26'],
            'block_type' => [$required, Rule::enum(RoomBlockTypeEnum::class)],
            'reason' => ['nullable', Rule::enum(RoomBlockReasonEnum::class)],
            'notes' => ['nullable', 'string'],
            'start_at' => [$required, 'date_format:Y-m-d H:i:s'],
            'end_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
        ]);
        $start = $data['start_at'] ?? $existingStart;
        $end = array_key_exists('end_at', $data) ? $data['end_at'] : $existingEnd;
        if ($end !== null && $start !== null && $end <= $start) {
            throw ValidationException::withMessages(['end_at' => ['End must follow start.']]);
        }
        return $data;
    }

    private function calendarDate(mixed $value, string $field): string
    {
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }
        if (! is_string($value) || ! $this->exactDate($value, 'Y-m-d')) {
            throw ValidationException::withMessages([$field => ['A calendar date in YYYY-MM-DD format is required.']]);
        }
        return $value;
    }

    private function dateTime(mixed $value, string $field): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d'] as $format) {
            if (is_string($value) && $this->exactDate($value, $format)) {
                return DateTimeImmutable::createFromFormat('!'.$format, $value)->format('Y-m-d H:i:s');
            }
        }
        throw ValidationException::withMessages([$field => ['A complete local date and time is required.']]);
    }

    private function exactDate(string $value, string $format): bool
    {
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
        return $date !== false && $date->format($format) === $value;
    }

    private function check(array $data, array $rules): void
    {
        Validator::make($data, $rules)->validate();
    }
}
