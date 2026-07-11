<?php

namespace Modules\Operations\PMS\Services\Ports;

/**
 * PMS Guest Ledger — Completed Settlement Conflict Read Port (GLF-D).
 *
 * Evaluates whether a conflicting completed settlement already exists for
 * the checkout-relevant stay/reservation. Consumed by the settlement-readiness
 * projection.
 *
 * Returns one of:
 *   - AVAILABLE_CLEAR    — no conflicting completed settlement exists.
 *   - AVAILABLE_BLOCKED  — a conflicting completed settlement exists.
 *   - REVIEW_REQUIRED    — evidence is ambiguous or requires human review.
 *   - EVIDENCE_UNAVAILABLE — authoritative source does not exist.
 */
interface GuestLedgerCompletedSettlementConflictReadPort
{
    public const AVAILABLE_CLEAR    = 'AVAILABLE_CLEAR';
    public const AVAILABLE_BLOCKED  = 'AVAILABLE_BLOCKED';
    public const REVIEW_REQUIRED    = 'REVIEW_REQUIRED';
    public const EVIDENCE_UNAVAILABLE = 'EVIDENCE_UNAVAILABLE';

    /**
     * @param  string $reservationId
     * @param  string $propertyId
     * @return array{status: string, code: string|null, message: string|null}
     */
    public function evaluate(string $reservationId, string $propertyId): array;
}
