<?php

namespace Modules\Operations\FrontDesk\Services;

use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutSettlementReadinessProjectionService;

class FrontDeskGuestLedgerSettlementReadinessDependencyService
{
    public const VIEW_PERMISSION = GuestLedgerCheckoutSettlementReadinessProjectionService::VIEW_PERMISSION;

    public function __construct(
        private readonly GuestLedgerCheckoutSettlementReadinessProjectionService $projectionService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function project(User $actor, string $frontDeskStayId): array
    {
        $projection = $this->projectionService->project($actor, $frontDeskStayId);

        return [
            'projection_version' => $projection->projection_version,
            'status' => $projection->status->value,
            'property_id' => $projection->property_id,
            'front_desk_stay_id' => $projection->front_desk_stay_id,
            'reservation_id' => $projection->reservation_id,
            'guest_id' => $projection->guest_id,
            'folio_ids' => $projection->folio_ids,
            'folio_count' => $projection->folio_count,
            'canonical_aggregate_balance' => $projection->canonical_aggregate_balance,
            'currency' => $projection->currency,
            'blocker_codes' => $projection->blocker_codes,
            'blocker_messages' => $projection->blocker_messages,
            'review_reasons' => $projection->review_reasons,
            'evidence_unavailable_codes' => $projection->evidence_unavailable_codes,
            'markers' => $projection->markers,
            'evaluated_at' => $projection->evaluated_at,
            'source_fingerprint' => $projection->source_fingerprint,
        ];
    }
}
