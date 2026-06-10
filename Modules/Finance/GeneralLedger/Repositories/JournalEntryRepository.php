<?php

namespace Modules\Finance\GeneralLedger\Repositories;

use Modules\Finance\GeneralLedger\Models\JournalEntry;

class JournalEntryRepository
{
    public function createWithLines(array $entryData, array $linesData): JournalEntry
    {
        $entry = JournalEntry::create($entryData);
        
        foreach ($linesData as $line) {
            $entry->lines()->create($line);
        }

        return $entry->load('lines');
    }

    public function findByIdAndProperty(string $id, string $propertyId): ?JournalEntry
    {
        return JournalEntry::with('lines')->where('id', $id)->where('property_id', $propertyId)->first();
    }
}
