<?php

namespace Modules\Operations\Logbook\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Operations\Logbook\Models\LogbookEntry;
use Modules\Operations\Logbook\Models\LogbookEntryFollowUpResolution;
use Modules\Operations\Logbook\Enums\LogbookEntryStatusEnum;
use Shared\Services\CurrentPropertyService;

class LogbookEntryFollowUpResolutionService
{
    public function resolve(string $entryId, array $data, string $userId): LogbookEntryFollowUpResolution
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();

        if (empty($data['resolution_note'])) {
            throw ValidationException::withMessages([
                'resolution_note' => 'The resolution note field is required.'
            ]);
        }

        try {
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
                    throw new \Exception("Only submitted entries can be resolved.");
                }

                // 3. Parent requires_follow_up is true
                if (!$entry->requires_follow_up) {
                    throw new \Exception("Only entries requiring follow-up can be resolved.");
                }

                // 6. Actor must be original creator
                if ($entry->created_by !== $userId) {
                    throw new AuthorizationException("Only the original creator can resolve this follow-up.");
                }

                // 4. Parent has no existing resolution
                $existing = LogbookEntryFollowUpResolution::where('logbook_entry_id', $entryId)->exists();
                if ($existing) {
                    throw ValidationException::withMessages([
                        'follow_up_resolution' => 'This follow-up has already been resolved.'
                    ]);
                }

                // Create resolution
                return LogbookEntryFollowUpResolution::create([
                    'property_id' => $propertyId,
                    'logbook_entry_id' => $entryId,
                    'resolution_note' => $data['resolution_note'],
                    'resolved_by' => $userId,
                    'resolved_at' => now(),
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Check for PostgreSQL unique violation code (23505) on the specific constraint
            if ($e->getCode() == '23505' && str_contains($e->getMessage(), 'logbook_entry_resolution_unique')) {
                throw ValidationException::withMessages([
                    'follow_up_resolution' => 'This follow-up has already been resolved.'
                ]);
            }
            throw $e;
        }
    }
}
