<?php

namespace Modules\Operations\PMS\Services\Ports;

/**
 * PMS Guest Ledger — Posting Completeness Participation Port (GLF-E).
 *
 * Transaction-participating port consumed by locked terminal evaluation.
 * Distinct from GLF-D read port: this port requires the caller's active
 * PostgreSQL transaction and must lock its own mutable source rows.
 *
 * Returns one of:
 *   - AVAILABLE_CLEAR    — mandatory postings are complete.
 *   - AVAILABLE_BLOCKED  — mandatory postings are incomplete.
 *   - REVIEW_REQUIRED    — evidence is ambiguous or requires human review.
 *   - EVIDENCE_UNAVAILABLE — authoritative source does not exist.
 */
interface GuestLedgerPostingCompletenessParticipationPort
{
    public const AVAILABLE_CLEAR = 'AVAILABLE_CLEAR';
    public const AVAILABLE_BLOCKED = 'AVAILABLE_BLOCKED';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    public const EVIDENCE_UNAVAILABLE = 'EVIDENCE_UNAVAILABLE';

    /**
     * @param  string $reservationId
     * @param  string $propertyId
     * @return array{status: string, code: string|null, source_fingerprint: string|null, source_identifiers: array}
     */
    public function participate(string $reservationId, string $propertyId): array;
}
