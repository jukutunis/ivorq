<?php

namespace Modules\Operations\PMS\Enums;

/**
 * PMS Guest Ledger — Settlement Readiness Status (ADR-088).
 *
 * GLF-D: Read-only projection statuses. The projection does not persist
 * a status — it returns this enum as part of the immutable value object.
 *
 * Status precedence:
 *   1. EVIDENCE_UNAVAILABLE when any mandatory authoritative source is unavailable.
 *   2. REVIEW_REQUIRED when no required source is unavailable but evidence is
 *      ambiguous, conflicting or internally inconsistent.
 *   3. BLOCKED when all mandatory sources are available and source evidence proves
 *      one or more requirements are unmet.
 *   4. READY only when all requirements pass.
 */
enum GuestLedgerSettlementReadinessStatusEnum: string
{
    case GuestLedgerSettlementReady = 'GUEST_LEDGER_SETTLEMENT_READY';
    case GuestLedgerSettlementBlocked = 'GUEST_LEDGER_SETTLEMENT_BLOCKED';
    case GuestLedgerSettlementReviewRequired = 'GUEST_LEDGER_SETTLEMENT_REVIEW_REQUIRED';
    case GuestLedgerSettlementEvidenceUnavailable = 'GUEST_LEDGER_SETTLEMENT_EVIDENCE_UNAVAILABLE';

    public function label(): string
    {
        return match ($this) {
            self::GuestLedgerSettlementReady => 'Settlement Ready',
            self::GuestLedgerSettlementBlocked => 'Settlement Blocked',
            self::GuestLedgerSettlementReviewRequired => 'Settlement Review Required',
            self::GuestLedgerSettlementEvidenceUnavailable => 'Settlement Evidence Unavailable',
        };
    }
}
