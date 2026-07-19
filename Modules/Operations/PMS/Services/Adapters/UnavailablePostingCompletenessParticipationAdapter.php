<?php

namespace Modules\Operations\PMS\Services\Adapters;

use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;

/**
 * GLF-E: Unavailable posting-completeness participation adapter.
 *
 * No authoritative transactional posting-completeness source exists.
 * Always returns EVIDENCE_UNAVAILABLE — fail-closed until a real
 * implementation exists (future Night Audit / Business Date).
 */
class UnavailablePostingCompletenessParticipationAdapter implements GuestLedgerPostingCompletenessParticipationPort
{
    public function participate(string $reservationId, string $propertyId): array
    {
        return [
            'status' => self::EVIDENCE_UNAVAILABLE,
            'code' => 'POSTING_COMPLETENESS_EVIDENCE_UNAVAILABLE',
            'source_fingerprint' => hash('sha256', 'unavailable_posting_completeness'),
            'source_identifiers' => [],
        ];
    }
}
