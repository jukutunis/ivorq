<?php

namespace Modules\Operations\PMS\Listeners;

use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Events\GuestCheckedIn;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Services\GuestLedgerFolioAggregateService;

/**
 * System-driven default folio creation on guest check-in.
 *
 * GLF-A: Routes through GuestLedgerFolioAggregateService::openWindowSystem().
 * The aggregate service independently resolves property, guest, and currency
 * from authoritative database sources. The listener passes only the
 * reservation identifier — no property ID, guest ID, currency, totals,
 * status, or window number.
 */
class CreateFolioIfMissing
{
    public function __construct(
        private GuestLedgerFolioAggregateService $aggregate,
    ) {}

    public function handle(GuestCheckedIn $event): void
    {
        $reservation = $event->reservation;

        // Idempotency check: only create if no open folio exists yet.
        $hasOpenFolio = Folio::where('reservation_id', $reservation->id)
            ->where('status', FolioStatusEnum::Open)
            ->exists();

        if ($hasOpenFolio) {
            return;
        }

        // The aggregate service independently resolves property, guest,
        // and currency. The event object is NOT trusted for these values.
        $this->aggregate->openWindowSystem(
            reservationId: $reservation->id,
            sourcePurpose: 'check-in-listener',
        );
    }
}
