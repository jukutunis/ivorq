<?php

namespace Modules\Operations\PMS\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\Operations\PMS\Enums\GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum;

/**
 * PMS Guest Ledger / PMS Cashiering — Immutable Terminal Financial Attestation (GLF-E).
 *
 * A deterministic, transaction-bound, read-only value object returned by
 * the terminal attestation service. All fields are server-resolved inside
 * the caller's active PostgreSQL transaction. The browser must never supply
 * any field.
 *
 * This attestation is binding only while the PMS-owned locks remain held.
 * It must not be persisted or trusted outside the issuing transaction.
 */
final class GuestLedgerCheckoutTerminalFinancialAttestation
{
    public const VERSION = 'GLF-E-PMS-TERMINAL-FINANCIAL-v1';
    public const OWNER = 'PMS Guest Ledger / PMS Cashiering';

    /**
     * @param string[] $folio_ids
     * @param string[] $blocker_codes
     * @param string[] $review_reasons
     * @param string[] $evidence_unavailable_codes
     * @param array<string, string> $markers
     * @param array<int, array{source_type: string, source_id: string, cashier_session_id: string}> $cash_linked_references
     * @param string[] $cashier_session_ids
     */
    private function __construct(
        public readonly string                                              $attestation_version,
        public readonly GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum $status,
        public readonly string                                              $owner,
        public readonly bool                                                $transaction_bound,
        public readonly string                                              $property_id,
        public readonly string                                              $property_business_date_id,
        public readonly string                                              $business_date,
        public readonly string                                              $front_desk_stay_id,
        public readonly string                                              $reservation_id,
        public readonly array                                               $folio_ids,
        public readonly int                                                 $folio_count,
        public readonly string                                              $canonical_aggregate_balance,
        public readonly ?string                                             $currency,
        public readonly array                                               $blocker_codes,
        public readonly array                                               $review_reasons,
        public readonly array                                               $evidence_unavailable_codes,
        public readonly array                                               $cash_linked_references,
        public readonly array                                               $cashier_session_ids,
        public readonly string                                              $source_fingerprint,
        public readonly string                                              $evaluated_at,
        public readonly array                                               $markers,
    ) {}

    /**
     * Named constructor — all fields are server-resolved inside the
     * caller's active PostgreSQL transaction.
     *
     * @param string[] $folio_ids
     * @param string[] $blocker_codes
     * @param string[] $review_reasons
     * @param string[] $evidence_unavailable_codes
     * @param array<int, array{source_type: string, source_id: string, cashier_session_id: string}> $cash_linked_references
     * @param string[] $cashier_session_ids
     * @param array<string, string> $markers
     */
    public static function create(
        GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum $status,
        string $property_id,
        string $property_business_date_id,
        string $business_date,
        string $front_desk_stay_id,
        string $reservation_id,
        array $folio_ids,
        int $folio_count,
        string $canonical_aggregate_balance,
        ?string $currency,
        array $blocker_codes,
        array $review_reasons,
        array $evidence_unavailable_codes,
        array $cash_linked_references,
        array $cashier_session_ids,
        string $source_fingerprint,
        string $evaluated_at,
        array $markers,
    ): self {
        sort($blocker_codes);
        sort($review_reasons);
        sort($evidence_unavailable_codes);
        sort($folio_ids);
        sort($cashier_session_ids);

        usort($cash_linked_references, function (array $a, array $b): int {
            return ($a['source_type'] . $a['source_id'] . $a['cashier_session_id'])
                <=> ($b['source_type'] . $b['source_id'] . $b['cashier_session_id']);
        });

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
            folio_ids: array_values(array_unique($folio_ids)),
            folio_count: $folio_count,
            canonical_aggregate_balance: $canonical_aggregate_balance,
            currency: $currency,
            blocker_codes: array_values(array_unique($blocker_codes)),
            review_reasons: array_values(array_unique($review_reasons)),
            evidence_unavailable_codes: array_values(array_unique($evidence_unavailable_codes)),
            cash_linked_references: array_values($cash_linked_references),
            cashier_session_ids: array_values(array_unique($cashier_session_ids)),
            source_fingerprint: $source_fingerprint,
            evaluated_at: $evaluated_at,
            markers: $markers,
        );
    }
}
