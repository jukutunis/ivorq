<?php

namespace Modules\Operations\PMS\ValueObjects;

use Modules\Operations\PMS\Enums\GuestLedgerSettlementReadinessStatusEnum;

/**
 * PMS Guest Ledger — Immutable Checkout Settlement Readiness Projection (GLF-D).
 *
 * A deterministic, read-only value object returned by the projection service.
 * All fields are server-resolved. The browser must never supply any field.
 *
 * ADR-088 compliant output contract.
 */
final class GuestLedgerCheckoutSettlementReadinessProjection
{
    /** @param string[] $folio_ids */
    /** @param string[] $blocker_codes */
    /** @param string[] $blocker_messages */
    /** @param string[] $review_reasons */
    /** @param string[] $evidence_unavailable_codes */
    /** @param array<string, string> $markers */
    /** @param array<string, string> $source_identifiers */
    private function __construct(
        public readonly string                                   $projection_version,
        public readonly GuestLedgerSettlementReadinessStatusEnum $status,
        public readonly string                                   $property_id,
        public readonly string                                   $front_desk_stay_id,
        public readonly string                                   $reservation_id,
        public readonly string                                   $guest_id,
        public readonly array                                    $folio_ids,
        public readonly int                                      $folio_count,
        public readonly string                                   $canonical_aggregate_balance,
        public readonly ?string                                  $currency,
        public readonly array                                    $blocker_codes,
        public readonly array                                    $blocker_messages,
        public readonly array                                    $review_reasons,
        public readonly array                                    $evidence_unavailable_codes,
        public readonly array                                    $markers,
        public readonly string                                   $evaluated_at,
        public readonly string                                   $source_fingerprint,
        public readonly array                                    $source_identifiers,
    ) {}

    /**
     * Named constructor — all fields are server-resolved.
     */
    public static function create(
        string                                   $projection_version,
        GuestLedgerSettlementReadinessStatusEnum $status,
        string                                   $property_id,
        string                                   $front_desk_stay_id,
        string                                   $reservation_id,
        string                                   $guest_id,
        array                                    $folio_ids,
        int                                      $folio_count,
        string                                   $canonical_aggregate_balance,
        ?string                                  $currency,
        array                                    $blocker_codes,
        array                                    $blocker_messages,
        array                                    $review_reasons,
        array                                    $evidence_unavailable_codes,
        array                                    $markers,
        string                                   $evaluated_at,
        string                                   $source_fingerprint,
        array                                    $source_identifiers,
    ): self {
        // Sort and deduplicate codes deterministically
        sort($blocker_codes);
        sort($blocker_messages);
        sort($review_reasons);
        sort($evidence_unavailable_codes);
        sort($folio_ids);

        return new self(
            projection_version: $projection_version,
            status: $status,
            property_id: $property_id,
            front_desk_stay_id: $front_desk_stay_id,
            reservation_id: $reservation_id,
            guest_id: $guest_id,
            folio_ids: array_values(array_unique($folio_ids)),
            folio_count: $folio_count,
            canonical_aggregate_balance: $canonical_aggregate_balance,
            currency: $currency,
            blocker_codes: array_values(array_unique($blocker_codes)),
            blocker_messages: array_values(array_unique($blocker_messages)),
            review_reasons: array_values(array_unique($review_reasons)),
            evidence_unavailable_codes: array_values(array_unique($evidence_unavailable_codes)),
            markers: $markers,
            evaluated_at: $evaluated_at,
            source_fingerprint: $source_fingerprint,
            source_identifiers: $source_identifiers,
        );
    }
}
