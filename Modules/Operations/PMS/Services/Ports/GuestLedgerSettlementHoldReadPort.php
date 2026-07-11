<?php

namespace Modules\Operations\PMS\Services\Ports;

/**
 * PMS Guest Ledger — Settlement Hold Read Port (GLF-D).
 *
 * Evaluates whether a settlement hold is active for the checkout-relevant
 * stay/reservation. Consumed by the settlement-readiness projection.
 *
 * Returns one of:
 *   - AVAILABLE_CLEAR    — no active settlement hold exists.
 *   - AVAILABLE_BLOCKED  — an active settlement hold is present.
 *   - REVIEW_REQUIRED    — evidence is ambiguous or requires human review.
 *   - EVIDENCE_UNAVAILABLE — authoritative source does not exist.
 */
interface GuestLedgerSettlementHoldReadPort
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
