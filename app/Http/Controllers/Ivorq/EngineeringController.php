<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class EngineeringController extends Controller
{
    public function workOrders()
    {
        return Inertia::render('Ivorq/Engineering/EngineeringWorkspace', ['activeTab' => 'work_orders']);
    }

    public function preventiveMaintenance()
    {
        return Inertia::render('Ivorq/Engineering/EngineeringWorkspace', ['activeTab' => 'preventive_maintenance']);
    }

    public function assetRegistry()
    {
        return Inertia::render('Ivorq/Engineering/EngineeringWorkspace', ['activeTab' => 'asset_registry']);
    }

    public function technicianSchedule()
    {
        return Inertia::render('Ivorq/Engineering/EngineeringWorkspace', ['activeTab' => 'technician_schedule']);
    }
}
