<?php

namespace Modules\Operations\Logbook\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Operations\Logbook\Models\ShiftLog;
use Modules\Operations\Logbook\Enums\ShiftLogStatusEnum;
use Modules\Foundation\Department\Models\Shift;
use Modules\Foundation\Department\Models\Department;
use Shared\Services\CurrentPropertyService;

class ShiftLogService
{
    public function createDraft(array $data, string $userId): ShiftLog
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        // Validate optional shift_id and department_id belong to resolved property
        $this->validateReferences($propertyId, $data);

        return DB::transaction(function () use ($data, $propertyId, $userId) {
            return ShiftLog::create(array_merge($data, [
                'property_id' => $propertyId,
                'created_by' => $userId,
                'status' => ShiftLogStatusEnum::Draft->value,
            ]));
        });
    }

    public function updateDraft(ShiftLog $log, array $data, string $userId): ShiftLog
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        if ($log->property_id !== $propertyId) {
            throw new AuthorizationException("Property context mismatch.");
        }

        if ($log->status !== ShiftLogStatusEnum::Draft) {
            throw new \Exception("Only draft shift logs can be edited.");
        }

        if ($log->created_by !== $userId) {
            throw new AuthorizationException("Only the creator can edit their own draft.");
        }

        $this->validateReferences($propertyId, $data);

        return DB::transaction(function () use ($log, $data) {
            $log->update($data);
            return $log->fresh();
        });
    }

    public function submit(ShiftLog $log, string $userId): ShiftLog
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        if ($log->property_id !== $propertyId) {
            throw new AuthorizationException("Property context mismatch.");
        }

        if ($log->status !== ShiftLogStatusEnum::Draft) {
            throw new \Exception("Only draft shift logs can be submitted for handover.");
        }

        if ($log->created_by !== $userId) {
            throw new AuthorizationException("Only the creator can submit their own draft.");
        }

        if (empty($log->department_id)) {
            throw new \Exception("The shift log must have a valid department before submission.");
        }

        $deptExists = Department::withoutGlobalScope('property')
            ->where('id', $log->department_id)
            ->where('property_id', $propertyId)
            ->exists();
        if (!$deptExists) {
            throw new \Exception("The department must belong to the active property.");
        }

        return DB::transaction(function () use ($log, $userId) {
            $log->update([
                'status' => ShiftLogStatusEnum::Submitted->value,
                'submitted_by' => $userId,
                'submitted_at' => now(),
            ]);
            return $log->fresh();
        });
    }

    public function acknowledge(ShiftLog $log, string $userId): ShiftLog
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        if ($log->property_id !== $propertyId) {
            throw new AuthorizationException("Property context mismatch.");
        }

        if ($log->status !== ShiftLogStatusEnum::Submitted) {
            throw new \Exception("Only submitted shift logs can be acknowledged.");
        }

        if ($log->created_by === $userId) {
            throw new \Exception("A user cannot acknowledge their own shift log.");
        }

        return DB::transaction(function () use ($log, $userId) {
            $log->update([
                'status' => ShiftLogStatusEnum::Acknowledged->value,
                'acknowledged_by' => $userId,
                'acknowledged_at' => now(),
            ]);
            return $log->fresh();
        });
    }

    private function validateReferences(string $propertyId, array $data): void
    {
        if (empty($data['department_id'])) {
            throw ValidationException::withMessages([
                'department_id' => 'The department field is required.'
            ]);
        }

        $deptExists = Department::withoutGlobalScope('property')
            ->where('id', $data['department_id'])
            ->where('property_id', $propertyId)
            ->exists();
        if (!$deptExists) {
            throw ValidationException::withMessages([
                'department_id' => 'The selected department must belong to the active property.'
            ]);
        }

        if (!empty($data['shift_id'])) {
            $shiftExists = Shift::withoutGlobalScope('property')
                ->where('id', $data['shift_id'])
                ->where('property_id', $propertyId)
                ->exists();
            if (!$shiftExists) {
                throw ValidationException::withMessages([
                    'shift_id' => 'The selected shift must belong to the active property.'
                ]);
            }
        }
    }
}
