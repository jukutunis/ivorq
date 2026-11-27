<?php

namespace Modules\Operations\FrontDesk\Services;

use Modules\Operations\FrontDesk\Models\FrontDeskStay;

/** Read contract for PMS commercial changes; a CONFIRMED reservation can already have a stay. */
class FrontDeskReservationEvidence
{
    public function exists(string $propertyId, string $reservationId): bool
    {
        return FrontDeskStay::query()
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)
            ->exists();
    }
}
