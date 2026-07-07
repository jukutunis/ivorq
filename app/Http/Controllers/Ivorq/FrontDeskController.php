<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Operations\FrontDesk\Services\ArrivalEligibilityProjectionService;

class FrontDeskController extends Controller
{
    public function arrivals(Request $request, ArrivalEligibilityProjectionService $arrivals)
    {
        return Inertia::render('Ivorq/FrontDesk/FrontDeskWorkspace', [
            'activeTab' => 'arrivals',
            'arrivalWorkspace' => $arrivals->workspace($request->user(), $request->only(['search', 'arrival_date'])),
        ]);
    }

    public function departures()
    {
        return Inertia::render('Ivorq/FrontDesk/FrontDeskWorkspace', ['activeTab' => 'departures']);
    }

    public function inHouse()
    {
        return Inertia::render('Ivorq/FrontDesk/FrontDeskWorkspace', ['activeTab' => 'in_house']);
    }

    public function roomReadiness()
    {
        return Inertia::render('Ivorq/FrontDesk/FrontDeskWorkspace', ['activeTab' => 'room_readiness']);
    }

    public function reservationBoard()
    {
        return Inertia::render('Ivorq/FrontDesk/FrontDeskWorkspace', ['activeTab' => 'reservation_board']);
    }
}
