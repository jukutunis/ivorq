<?php

namespace Modules\Operations\PMS\Services\Adapters;

use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;

/**
 * GLF-E: Unavailable settlement-hold participation adapter.
 *
 * No authoritative transactional settlement-hold source exists.
 * Always returns EVIDENCE_UNAVAILABLE — fail-closed until a real
 * implementation exists.
 */
class UnavailableSettlementHoldParticipationAdapter implements GuestLedgerSettlementHoldParticipationPort
{
    public function participate(string $reservationId, string $propertyId): array
    {
        return [
            'status' => self::EVIDENCE_UNAVAILABLE,
            'code' => 'SETTLEMENT_HOLD_EVIDENCE_UNAVAILABLE',
            'source_fingerprint' => hash('sha256', 'unavailable_settlement_hold'),
            'source_identifiers' => [],
        ];
    }
}
