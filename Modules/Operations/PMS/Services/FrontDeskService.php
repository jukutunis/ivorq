<?php

namespace Modules\Operations\PMS\Services;

use Modules\Operations\PMS\Models\Stay;

/** Legacy PMS stay commands are retained as explicit denial boundaries. */
class FrontDeskService
{
    public function checkIn(string $reservationId): Stay
    {
        abort(409, 'Use the controlled Front Desk check-in workspace.');
    }

    public function checkOut(string $stayId): Stay
    {
        abort(409, 'Use the controlled Package 9 checkout workspace.');
    }
}
