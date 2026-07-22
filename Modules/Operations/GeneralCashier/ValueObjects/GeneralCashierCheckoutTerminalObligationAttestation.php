<?php

namespace Modules\Operations\GeneralCashier\ValueObjects;

use Modules\Operations\GeneralCashier\Enums\GeneralCashierCheckoutTerminalObligationAttestationStatusEnum;

/**
 * General Cashier — Immutable Terminal Obligation Attestation (GC-A2).
 *
 * A deterministic, transaction-bound, read-only value object returned by
 * the terminal obligation attestation service. All fields are server-resolved
 * inside the caller's active PostgreSQL transaction. The browser must never
 * supply any field.
 *
 * This attestation is binding only while the General Cashier-owned locks
 * remain held. It must not be persisted or trusted outside the issuing
 * transaction.
 *
 * Required markers:
 *   - attestation_owner = GENERAL_CASHIER
 *   - transaction_boundary = ACTIVE_POSTGRESQL_TRANSACTION
 *   - pms_reference_contract = EXACT_GLF_E_ATTESTATION
 *   - cashier_obligation_scope_marker = NO_AUTHORITATIVE_CASHIER_OBLIGATIONS
 *       or AUTHORITATIVE_CASHIER_OBLIGATIONS_FOUND
 *   - cashier_accountability_marker = CASHIER_ACCOUNTABILITY_CLEAR
 *       or CASHIER_ACCOUNTABILITY_BLOCKED
 *       or CASHIER_ACCOUNTABILITY_REVIEW_REQUIRED
 *       or CASHIER_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE
 */
final class GeneralCashierCheckoutTerminalObligationAttestation
{
    public const VERSION = 'GC-A2-GENERAL-CASHIER-TERMINAL-OBLIGATION-v1';
    public const OWNER = 'General Cashier';

    /**
     * @param string[] $blocker_codes
     * @param string[] $review_reasons
     * @param string[] $evidence_unavailable_codes
     * @param string[] $cashier_session_ids
     * @param array<string, string> $markers
     */
    private function __construct(
        public readonly string                                                         $attestation_version,
        public readonly GeneralCashierCheckoutTerminalObligationAttestationStatusEnum   $status,
        public readonly string                                                         $owner,
        public readonly bool                                                           $transaction_bound,
        public readonly string                                                         $property_id,
        public readonly string                                                         $property_business_date_id,
        public readonly string                                                         $business_date,
        public readonly string                                                         $front_desk_stay_id,
        public readonly string                                                         $reservation_id,
        public readonly string                                                         $consumed_pms_status,
        public readonly string                                                         $consumed_pms_source_fingerprint,
        public readonly array                                                          $cashier_session_ids,
        public readonly int                                                            $cash_linked_reference_count,
        public readonly array                                                          $blocker_codes,
        public readonly array                                                          $review_reasons,
        public readonly array                                                          $evidence_unavailable_codes,
        public readonly string                                                         $source_fingerprint,
        public readonly string                                                         $evaluated_at,
        public readonly array                                                          $markers,
    ) {}

    /**
     * Named constructor — all fields are server-resolved inside the
     * caller's active PostgreSQL transaction.
     *
     * @param string[] $blocker_codes
     * @param string[] $review_reasons
     * @param string[] $evidence_unavailable_codes
     * @param string[] $cashier_session_ids
     * @param array<string, string> $markers
     */
    public static function create(
        GeneralCashierCheckoutTerminalObligationAttestationStatusEnum $status,
        string $property_id,
        string $property_business_date_id,
        string $business_date,
        string $front_desk_stay_id,
        string $reservation_id,
        string $consumed_pms_status,
        string $consumed_pms_source_fingerprint,
        array $cashier_session_ids,
        int $cash_linked_reference_count,
        array $blocker_codes,
        array $review_reasons,
        array $evidence_unavailable_codes,
        string $source_fingerprint,
        string $evaluated_at,
        array $markers,
    ): self {
        sort($blocker_codes);
        sort($review_reasons);
        sort($evidence_unavailable_codes);
        sort($cashier_session_ids);
        ksort($markers);

        return new self(
            attestation_version: self::VERSION,
            status: $status,
            owner: self::OWNER,
            transaction_bound: true,
            property_id: $property_id,
            property_business_date_id: $property_business_date_id,
            business_date: $business_date,
            front_desk_stay_id: $front_desk_stay_id,
            reservation_id: $reservation_id,
            consumed_pms_status: $consumed_pms_status,
            consumed_pms_source_fingerprint: $consumed_pms_source_fingerprint,
            cashier_session_ids: array_values(array_unique($cashier_session_ids)),
            cash_linked_reference_count: $cash_linked_reference_count,
            blocker_codes: array_values(array_unique($blocker_codes)),
            review_reasons: array_values(array_unique($review_reasons)),
            evidence_unavailable_codes: array_values(array_unique($evidence_unavailable_codes)),
            source_fingerprint: $source_fingerprint,
            evaluated_at: $evaluated_at,
            markers: $markers,
        );
    }
}
