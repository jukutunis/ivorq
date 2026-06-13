<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class HousekeepingController extends Controller
{
    public function roomBoard()
    {
        return Inertia::render('Ivorq/Housekeeping/HousekeepingWorkspace', ['activeTab' => 'room_board']);
    }

    public function attendantStatus()
    {
        return Inertia::render('Ivorq/Housekeeping/HousekeepingWorkspace', ['activeTab' => 'attendant_status']);
    }

    public function inspections()
    {
        return Inertia::render('Ivorq/Housekeeping/HousekeepingWorkspace', ['activeTab' => 'inspections']);
    }

    public function lostFound()
    {
        return Inertia::render('Ivorq/Housekeeping/HousekeepingWorkspace', ['activeTab' => 'lost_found']);
    }
}
