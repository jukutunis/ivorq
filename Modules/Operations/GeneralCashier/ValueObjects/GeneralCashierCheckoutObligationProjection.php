<?php

namespace Modules\Operations\GeneralCashier\ValueObjects;

use Modules\Operations\GeneralCashier\Enums\GeneralCashierCheckoutObligationStatusEnum;

final class GeneralCashierCheckoutObligationProjection
{
    private function __construct(
        public readonly string $projection_version,
        public readonly GeneralCashierCheckoutObligationStatusEnum $status,
        public readonly string $property_id,
        public readonly string $front_desk_stay_id,
        public readonly string $reservation_id,
        public readonly string $guest_id,
        public readonly array $related_guest_payment_transaction_ids,
        public readonly array $related_cashier_session_ids,
        public readonly array $blocker_codes,
        public readonly array $blocker_messages,
        public readonly array $review_reasons,
        public readonly array $evidence_unavailable_codes,
        public readonly array $markers,
        public readonly string $evaluated_at,
        public readonly string $source_fingerprint,
        public readonly array $source_identifiers,
    ) {}

    public static function create(
        string $projection_version,
        GeneralCashierCheckoutObligationStatusEnum $status,
        string $property_id,
        string $front_desk_stay_id,
        string $reservation_id,
        string $guest_id,
        array $related_guest_payment_transaction_ids,
        array $related_cashier_session_ids,
        array $blocker_codes,
        array $blocker_messages,
        array $review_reasons,
        array $evidence_unavailable_codes,
        array $markers,
        string $evaluated_at,
        string $source_fingerprint,
        array $source_identifiers,
    ): self {
        sort($related_guest_payment_transaction_ids);
        sort($related_cashier_session_ids);
        sort($blocker_codes);
        sort($blocker_messages);
        sort($review_reasons);
        sort($evidence_unavailable_codes);
        ksort($markers);
        ksort($source_identifiers);

        return new self(
            projection_version: $projection_version,
            status: $status,
            property_id: $property_id,
            front_desk_stay_id: $front_desk_stay_id,
            reservation_id: $reservation_id,
            guest_id: $guest_id,
            related_guest_payment_transaction_ids: array_values(array_unique($related_guest_payment_transaction_ids)),
            related_cashier_session_ids: array_values(array_unique($related_cashier_session_ids)),
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
