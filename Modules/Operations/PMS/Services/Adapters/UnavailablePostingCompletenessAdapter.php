<?php

namespace Modules\Operations\PMS\Services\Adapters;

use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort;

/**
 * GLF-D: Unavailable posting-completeness adapter.
 *
 * No authoritative posting-completeness source exists in the repository.
 * Always returns EVIDENCE_UNAVAILABLE — production READY is impossible
 * until a real implementation exists (future Night Audit / Business Date).
 */
class UnavailablePostingCompletenessAdapter implements GuestLedgerPostingCompletenessReadPort
{
    public function evaluate(string $reservationId, string $propertyId): array
    {
        return [
            'status'  => self::EVIDENCE_UNAVAILABLE,
            'code'    => 'POSTING_COMPLETENESS_EVIDENCE_UNAVAILABLE',
            'message' => 'No authoritative posting-completeness source exists.',
        ];
    }
}
