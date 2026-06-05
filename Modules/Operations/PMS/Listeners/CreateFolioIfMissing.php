<?php

namespace Modules\Operations\PMS\Listeners;

use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Events\GuestCheckedIn;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Repositories\FolioRepository;

class CreateFolioIfMissing
{
    public function __construct(
        private FolioRepository $folioRepository
    ) {}

    public function handle(GuestCheckedIn $event): void
    {
        $reservation = $event->reservation;
        $stay        = $event->stay;

        // Only create a folio if the reservation has no open folio yet
        $hasOpenFolio = Folio::where('reservation_id', $reservation->id)
            ->where('status', FolioStatusEnum::Open)
            ->exists();

        if ($hasOpenFolio) {
            return;
        }

        $this->folioRepository->create([
            'property_id'    => $reservation->property_id,
            'reservation_id' => $reservation->id,
            'guest_id'       => $stay->guest_id,
            'status'         => FolioStatusEnum::Open->value,
            'currency'       => $reservation->ratePlan?->currency ?? 'MYR',
            'total_charges'  => 0.00,
            'total_payments' => 0.00,
            'balance'        => 0.00,
        ]);
    }
}
