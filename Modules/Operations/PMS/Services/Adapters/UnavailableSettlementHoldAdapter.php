<?php

namespace Modules\Operations\PMS\Services\Adapters;

use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort;

/**
 * GLF-D: Unavailable settlement-hold adapter.
 *
 * No authoritative settlement-hold source exists in the repository.
 * Always returns EVIDENCE_UNAVAILABLE — production READY is impossible
 * until a real implementation exists.
 */
class UnavailableSettlementHoldAdapter implements GuestLedgerSettlementHoldReadPort
{
    public function evaluate(string $reservationId, string $propertyId): array
    {
        return [
            'status'  => self::EVIDENCE_UNAVAILABLE,
            'code'    => 'SETTLEMENT_HOLD_EVIDENCE_UNAVAILABLE',
            'message' => 'No authoritative settlement-hold source exists.',
        ];
    }
}
