<?php

namespace Modules\SalesAndEventManagement\Services;

use Modules\SalesAndEventManagement\Models\EventFunction;

class BEONumberGenerator
{
    /**
     * Generate BEO Number according to:
     * BEO-[PROPERTY]-[YEAR]-[FUNCTION]-R[REV]
     * Example: BEO-BLI-2026-F000124-R0
     */
    public function generate(EventFunction $function, string $propertyCode, int $revisionNumber = 0): string
    {
        $year = date('Y');
        
        // Temporary strategy for FUNCTION part, extracting numeric-like sequence from ULID
        // In reality, this would query a sequence generator or event_functions.id if it was integer.
        // We'll generate a consistent 6 digit number from the ULID's crc32
        $funcSeq = str_pad(substr((string) crc32($function->id), 0, 6), 6, '0', STR_PAD_LEFT);
        
        return sprintf('BEO-%s-%s-F%s-R%d', strtoupper($propertyCode), $year, $funcSeq, $revisionNumber);
    }
}
