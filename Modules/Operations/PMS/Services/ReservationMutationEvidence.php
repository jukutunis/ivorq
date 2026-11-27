<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\FrontDesk\Services\FrontDeskReservationEvidence;

/** Retained PMS and Front Desk evidence that freezes commercial reservation identity. */
class ReservationMutationEvidence
{
    public function __construct(private FrontDeskReservationEvidence $frontDeskEvidence) {}

    public function exists(string $propertyId, string $reservationId): bool
    {
        if ($this->frontDeskEvidence->exists($propertyId, $reservationId)) {
            return true;
        }

        foreach (['stays', 'folios', 'guest_payment_transactions',
            'guest_deposit_transactions', 'guest_refund_transactions',
            'guest_ar_transfer_requests'] as $table) {
            // Query retained rows directly: soft deletion, reversal, and zero balance
            // do not erase the original operational or financial evidence.
            if (DB::table($table)->where('property_id', $propertyId)
                ->where('reservation_id', $reservationId)->exists()) {
                return true;
            }
        }

        return DB::table('reservations')->where('property_id', $propertyId)
            ->where('id', $reservationId)->whereNotNull('assigned_room_id')->exists();
    }
}
