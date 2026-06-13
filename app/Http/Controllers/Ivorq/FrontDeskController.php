<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class FrontDeskController extends Controller
{
    public function arrivals()
    {
        return Inertia::render('Ivorq/FrontDesk/FrontDeskWorkspace', ['activeTab' => 'arrivals']);
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
