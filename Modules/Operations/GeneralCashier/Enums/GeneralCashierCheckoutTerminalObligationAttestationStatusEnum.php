<?php

namespace Modules\Operations\GeneralCashier\Enums;

/**
 * General Cashier — Terminal Obligation Attestation Status (GC-A2).
 *
 * Transaction-bound execution-time attestation statuses. These are distinct
 * from the GC-A1 read-only projection statuses. GC-A2 statuses are only valid
 * inside the caller's active PostgreSQL transaction while General Cashier-owned
 * locks remain held.
 *
 * Status precedence (highest first):
 *   1. GENERAL_CASHIER_TERMINAL_OBLIGATION_REVIEW_REQUIRED
 *   2. GENERAL_CASHIER_TERMINAL_OBLIGATION_EVIDENCE_UNAVAILABLE
 *   3. GENERAL_CASHIER_TERMINAL_OBLIGATION_BLOCKED
 *   4. GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR
 */
enum GeneralCashierCheckoutTerminalObligationAttestationStatusEnum: string
{
    case GeneralCashierTerminalObligationClear = 'GENERAL_CASHIER_TERMINAL_OBLIGATION_CLEAR';
    case GeneralCashierTerminalObligationBlocked = 'GENERAL_CASHIER_TERMINAL_OBLIGATION_BLOCKED';
    case GeneralCashierTerminalObligationReviewRequired = 'GENERAL_CASHIER_TERMINAL_OBLIGATION_REVIEW_REQUIRED';
    case GeneralCashierTerminalObligationEvidenceUnavailable = 'GENERAL_CASHIER_TERMINAL_OBLIGATION_EVIDENCE_UNAVAILABLE';

    public function label(): string
    {
        return match ($this) {
            self::GeneralCashierTerminalObligationClear => 'General Cashier Terminal Obligation Clear',
            self::GeneralCashierTerminalObligationBlocked => 'General Cashier Terminal Obligation Blocked',
            self::GeneralCashierTerminalObligationReviewRequired => 'General Cashier Terminal Obligation Review Required',
            self::GeneralCashierTerminalObligationEvidenceUnavailable => 'General Cashier Terminal Obligation Evidence Unavailable',
        };
    }
}
