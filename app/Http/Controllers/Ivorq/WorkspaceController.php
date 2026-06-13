<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkspaceController extends Controller
{
    public function frontdesk()
    {
        return Inertia::render('Ivorq/FrontDesk/FrontDeskWorkspace');
    }

    public function housekeeping()
    {
        return Inertia::render('Ivorq/Housekeeping/HousekeepingWorkspace');
    }

    public function engineering()
    {
        return Inertia::render('Ivorq/Engineering/EngineeringWorkspace');
    }

    public function finance()
    {
        return Inertia::render('Ivorq/Finance/FinanceWorkspace');
    }

    public function hris()
    {
        return Inertia::render('Ivorq/HRIS/HRISWorkspace');
    }
}
