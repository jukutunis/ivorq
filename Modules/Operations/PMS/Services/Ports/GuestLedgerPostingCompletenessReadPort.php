<?php

namespace Modules\Operations\PMS\Services\Ports;

/**
 * PMS Guest Ledger — Posting Completeness Read Port (GLF-D).
 *
 * Evaluates whether all mandatory operational charges for a checkout-relevant
 * stay/reservation have been posted. Consumed by the settlement-readiness
 * projection.
 *
 * Returns one of:
 *   - AVAILABLE_CLEAR    — mandatory postings are complete.
 *   - AVAILABLE_BLOCKED  — mandatory postings are incomplete.
 *   - REVIEW_REQUIRED    — evidence is ambiguous or requires human review.
 *   - EVIDENCE_UNAVAILABLE — authoritative source does not exist.
 */
interface GuestLedgerPostingCompletenessReadPort
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
