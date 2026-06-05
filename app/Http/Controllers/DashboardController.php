<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Enums\StayStatusEnum;
use Modules\Operations\PMS\Models\Stay;
use Modules\Operations\PMS\Repositories\ReservationRepository;

class DashboardController extends Controller
{
    public function __construct(
        private ReservationRepository $reservationRepository,
    ) {}

    public function index(): Response
    {
        $pmsStats = null;

        if (auth()->user()?->can('pms.reservation.view')) {
            $pmsStats = [
                'arrivals_today'   => $this->reservationRepository->arrivalsToday()->count(),
                'departures_today' => $this->reservationRepository->departuresToday()->count(),
                'in_house_count'   => Stay::where('status', StayStatusEnum::CheckedIn)->count(),
                'available_rooms'  => Room::where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNull('occupancy_status')
                          ->orWhere('occupancy_status', RoomOccupancyStatusEnum::Vacant);
                    })
                    ->count(),
            ];
        }

        return Inertia::render('Dashboard', [
            'pmsStats' => $pmsStats,
        ]);
    }
}
