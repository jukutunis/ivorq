<?php

namespace Modules\Operations\PMS\Listeners;

use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Events\GuestCheckedIn;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Services\GuestLedgerFolioAggregateService;

/**
 * System-driven default folio creation on guest check-in.
 *
 * GLF-A: Routes through GuestLedgerFolioAggregateService::openWindowSystem()
 * using a deterministic source-proven idempotency key so that replay is safe.
 * Property, guest, and currency are resolved from the check-in event context
 * — never from browser input.
 */
class CreateFolioIfMissing
{
    public function __construct(
        private GuestLedgerFolioAggregateService $aggregate,
    ) {}

    public function handle(GuestCheckedIn $event): void
    {
        $reservation = $event->reservation;
        $stay        = $event->stay;

        // Idempotency check: only create if no open folio exists yet.
        $hasOpenFolio = Folio::where('reservation_id', $reservation->id)
            ->where('status', FolioStatusEnum::Open)
            ->exists();

        if ($hasOpenFolio) {
            return;
        }

        // Resolve currency: prefer rate plan, fall back to property base currency.
        // The aggregate service will use the property currency if not explicitly
        // provided, but we resolve the best available source here for the
        // system-driven path.
        $currency = $reservation->ratePlan?->currency
            ?? $reservation->property?->currency
            ?? 'MYR';

        $this->aggregate->openWindowSystem(
            reservationId: $reservation->id,
            propertyId:    $reservation->property_id,
            guestId:       $stay->guest_id,
            currency:      $currency,
        );
    }
}
