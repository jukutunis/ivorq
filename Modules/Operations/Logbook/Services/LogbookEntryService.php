<?php

namespace Modules\Operations\Logbook\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Operations\Logbook\Models\LogbookEntry;
use Modules\Operations\Logbook\Enums\LogbookEntryStatusEnum;
use Modules\Foundation\Department\Models\Department;
use Shared\Services\CurrentPropertyService;

class LogbookEntryService
{
    public function createDraft(array $data, string $userId): LogbookEntry
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        $this->validateReferences($propertyId, $data);

        return DB::transaction(function () use ($data, $propertyId, $userId) {
            return LogbookEntry::create(array_merge($data, [
                'property_id' => $propertyId,
                'created_by' => $userId,
                'status' => LogbookEntryStatusEnum::Draft->value,
            ]));
        });
    }

    public function updateDraft(LogbookEntry $entry, array $data, string $userId): LogbookEntry
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        if ($entry->property_id !== $propertyId) {
            throw new AuthorizationException("Property context mismatch.");
        }

        if ($entry->status !== LogbookEntryStatusEnum::Draft) {
            throw new \Exception("Only draft entries can be edited.");
        }

        if ($entry->created_by !== $userId) {
            throw new AuthorizationException("Only the creator can edit their own draft.");
        }

        $this->validateReferences($propertyId, $data);

        return DB::transaction(function () use ($entry, $data) {
            $entry->update($data);
            return $entry->fresh();
        });
    }

    public function submit(LogbookEntry $entry, string $userId): LogbookEntry
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        if ($entry->property_id !== $propertyId) {
            throw new AuthorizationException("Property context mismatch.");
        }

        if ($entry->status !== LogbookEntryStatusEnum::Draft) {
            throw new \Exception("Only draft entries can be submitted.");
        }

        if ($entry->created_by !== $userId) {
            throw new AuthorizationException("Only the creator can submit their own draft.");
        }

        if (empty($entry->department_id)) {
            throw new \Exception("The entry must have a valid department before submission.");
        }

        $deptExists = Department::withoutGlobalScope('property')
            ->where('id', $entry->department_id)
            ->where('property_id', $propertyId)
            ->exists();
        if (!$deptExists) {
            throw new \Exception("The department must belong to the active property.");
        }

        return DB::transaction(function () use ($entry, $userId) {
            $entry->update([
                'status' => LogbookEntryStatusEnum::Submitted->value,
                'submitted_by' => $userId,
                'submitted_at' => now(),
            ]);
            return $entry->fresh();
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
    }
}
