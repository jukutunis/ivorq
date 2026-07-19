<?php

namespace Modules\Operations\PMS\Services\Adapters;

use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;

/**
 * GLF-E: Unavailable completed-settlement-conflict participation adapter.
 *
 * No authoritative transactional completed-settlement-conflict source exists.
 * Always returns EVIDENCE_UNAVAILABLE — fail-closed until a real
 * implementation exists.
 */
class UnavailableCompletedSettlementConflictParticipationAdapter implements GuestLedgerCompletedSettlementConflictParticipationPort
{
    public function participate(string $reservationId, string $propertyId): array
    {
        return [
            'status' => self::EVIDENCE_UNAVAILABLE,
            'code' => 'COMPLETED_SETTLEMENT_CONFLICT_EVIDENCE_UNAVAILABLE',
            'source_fingerprint' => hash('sha256', 'unavailable_completed_settlement_conflict'),
            'source_identifiers' => [],
        ];
    }
}
