<?php

namespace Modules\Operations\PMS\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Enums\StayStatusEnum;
use Modules\Operations\PMS\Http\Resources\ReservationResource;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Models\Stay;
use Modules\Operations\PMS\Services\PmsPropertyAuthority;

class PmsDashboardController extends Controller
{
    public function __construct(private PmsPropertyAuthority $authority) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Reservation::class);

        [, $propertyId] = $this->authority->require('pms.reservation.view');
        $arrivalsToday = Reservation::query()->where('property_id', $propertyId)
            ->whereDate('arrival_date', today())->where('status', ReservationStatusEnum::Confirmed)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('front_desk_stays')
                ->whereColumn('front_desk_stays.reservation_id', 'reservations.id')
                ->where('front_desk_stays.property_id', $propertyId)
                ->whereIn('front_desk_stays.status', [
                    FrontDeskStayStatusEnum::InHouse->value, FrontDeskStayStatusEnum::CheckedOut->value,
                ]))
            ->with('primaryGuest')->get();
        $departuresToday = Reservation::query()->where('property_id', $propertyId)
            ->whereDate('departure_date', today())
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('front_desk_stays')
                ->whereColumn('front_desk_stays.reservation_id', 'reservations.id')
                ->where('front_desk_stays.property_id', $propertyId)
                ->where('front_desk_stays.status', FrontDeskStayStatusEnum::InHouse->value))
            ->with('primaryGuest')->get();

        return Inertia::render('Operations/PMS/Dashboard/Index', [
            'stats' => [
                'arrivals_today' => $arrivalsToday->count(),
                'departures_today' => $departuresToday->count(),
                'in_house_count' => FrontDeskStay::query()->where('property_id', $propertyId)
                    ->where('status', FrontDeskStayStatusEnum::InHouse)->count(),
                'legacy_in_house_count' => Stay::query()->where('property_id', $propertyId)
                    ->where('status', StayStatusEnum::CheckedIn)->count(),
                'vacant_room_count' => Room::query()->where('property_id', $propertyId)->where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNull('occupancy_status')
                            ->orWhere('occupancy_status', RoomOccupancyStatusEnum::Vacant);
                    })
                    ->count(),
            ],
            'arrivals_today' => ReservationResource::collection($arrivalsToday),
            'departures_today' => ReservationResource::collection($departuresToday),
        ]);
    }
}
