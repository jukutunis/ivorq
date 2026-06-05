<?php

namespace Modules\Operations\PMS\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Enums\StayStatusEnum;
use Modules\Operations\PMS\Http\Resources\ReservationResource;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Models\Stay;
use Modules\Operations\PMS\Repositories\ReservationRepository;
use Modules\Operations\PMS\Repositories\StayRepository;

class PmsDashboardController extends Controller
{
    public function __construct(
        private ReservationRepository $reservationRepository,
        private StayRepository        $stayRepository,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Reservation::class);

        $arrivalsToday   = $this->reservationRepository->arrivalsToday();
        $departuresToday = $this->reservationRepository->departuresToday();

        return Inertia::render('Operations/PMS/Dashboard/Index', [
            'stats' => [
                'arrivals_today'   => $arrivalsToday->count(),
                'departures_today' => $departuresToday->count(),
                'in_house_count'   => Stay::where('status', StayStatusEnum::CheckedIn)->count(),
                'available_rooms'  => Room::where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNull('occupancy_status')
                          ->orWhere('occupancy_status', RoomOccupancyStatusEnum::Vacant);
                    })
                    ->count(),
            ],
            'arrivals_today'   => ReservationResource::collection($arrivalsToday),
            'departures_today' => ReservationResource::collection($departuresToday),
        ]);
    }
}
