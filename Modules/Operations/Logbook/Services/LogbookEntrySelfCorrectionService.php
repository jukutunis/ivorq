<?php

namespace Modules\Operations\Logbook\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Operations\Logbook\Models\LogbookEntry;
use Modules\Operations\Logbook\Models\LogbookEntrySelfCorrection;
use Modules\Operations\Logbook\Enums\LogbookEntryStatusEnum;
use Shared\Services\CurrentPropertyService;

class LogbookEntrySelfCorrectionService
{
    public function append(string $entryId, array $data, string $userId): LogbookEntrySelfCorrection
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        if (empty($data['correction_reason'])) {
            throw ValidationException::withMessages([
                'correction_reason' => 'The correction reason field is required.'
            ]);
        }

        if (empty($data['correction_content'])) {
            throw ValidationException::withMessages([
                'correction_content' => 'The correction content field is required.'
            ]);
        }

        return DB::transaction(function () use ($entryId, $data, $userId, $propertyId) {
            // Lock the parent entry row
            $entry = LogbookEntry::where('id', $entryId)
                ->lockForUpdate()
                ->first();

            if (!$entry) {
                throw new \Exception("Operational Log Entry not found.");
            }

            // 1. Parent belongs to resolved active Property
            if ($entry->property_id !== $propertyId) {
                throw new AuthorizationException("Property context mismatch.");
            }

            // 2. Parent status is Submitted
            if ($entry->status !== LogbookEntryStatusEnum::Submitted) {
                throw new \Exception("Self-correction can only be appended to submitted entries.");
            }

            // 3. Actor must be original creator
            if ($entry->created_by !== $userId) {
                throw new AuthorizationException("Only the original creator can append a self-correction.");
            }

            // Create self-correction record
            return LogbookEntrySelfCorrection::create([
                'property_id' => $propertyId,
                'logbook_entry_id' => $entryId,
                'correction_reason' => $data['correction_reason'],
                'correction_content' => $data['correction_content'],
                'corrected_by' => $userId,
                'corrected_at' => now(),
            ]);
        });
    }
}
