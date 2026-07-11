<?php

namespace Modules\Operations\PMS\Services\Adapters;

use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort;

/**
 * GLF-D: Unavailable completed-settlement-conflict adapter.
 *
 * No authoritative completed-settlement-conflict source exists in the repository.
 * Always returns EVIDENCE_UNAVAILABLE — production READY is impossible
 * until a real implementation exists.
 */
class UnavailableCompletedSettlementConflictAdapter implements GuestLedgerCompletedSettlementConflictReadPort
{
    public function evaluate(string $reservationId, string $propertyId): array
    {
        return [
            'status'  => self::EVIDENCE_UNAVAILABLE,
            'code'    => 'COMPLETED_SETTLEMENT_CONFLICT_EVIDENCE_UNAVAILABLE',
            'message' => 'No authoritative completed-settlement-conflict source exists.',
        ];
    }
}
