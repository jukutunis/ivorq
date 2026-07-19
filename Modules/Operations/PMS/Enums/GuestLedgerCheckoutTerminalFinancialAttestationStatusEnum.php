<?php

namespace Modules\Operations\PMS\Enums;

/**
 * PMS Guest Ledger / PMS Cashiering — Terminal Financial Attestation Status (GLF-E).
 *
 * Transaction-bound execution-time attestation statuses. These are distinct
 * from GLF-D read-only projection statuses. GLF-E statuses are only valid
 * inside the caller's active PostgreSQL transaction while PMS-owned locks
 * remain held.
 *
 * Status precedence (highest first):
 *   1. PMS_TERMINAL_FINANCIAL_EVIDENCE_UNAVAILABLE — mandatory source unavailable.
 *   2. PMS_TERMINAL_FINANCIAL_REVIEW_REQUIRED — evidence present but ambiguous.
 *   3. PMS_TERMINAL_FINANCIAL_BLOCKED — at least one requirement unmet.
 *   4. PMS_TERMINAL_FINANCIAL_READY — all mandatory conditions pass.
 */
enum GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum: string
{
    case PmsTerminalFinancialReady = 'PMS_TERMINAL_FINANCIAL_READY';
    case PmsTerminalFinancialBlocked = 'PMS_TERMINAL_FINANCIAL_BLOCKED';
    case PmsTerminalFinancialReviewRequired = 'PMS_TERMINAL_FINANCIAL_REVIEW_REQUIRED';
    case PmsTerminalFinancialEvidenceUnavailable = 'PMS_TERMINAL_FINANCIAL_EVIDENCE_UNAVAILABLE';

    public function label(): string
    {
        return match ($this) {
            self::PmsTerminalFinancialReady => 'PMS Terminal Financial Ready',
            self::PmsTerminalFinancialBlocked => 'PMS Terminal Financial Blocked',
            self::PmsTerminalFinancialReviewRequired => 'PMS Terminal Financial Review Required',
            self::PmsTerminalFinancialEvidenceUnavailable => 'PMS Terminal Financial Evidence Unavailable',
        };
    }
}
