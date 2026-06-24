<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class EngineeringController extends Controller
{
    public function workOrders()
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();

        $workOrders = \Modules\Operations\WorkOrder\Models\WorkOrder::where('property_id', $propertyId)
            ->with(['assignments.user', 'closures', 'labors'])
            ->latest()
            ->get();

        $technicians = \Modules\Foundation\User\Models\User::whereHas('properties', function ($query) use ($propertyId) {
            $query->where('properties.id', $propertyId);
        })->get(['users.id', 'users.name']);

        return Inertia::render('Ivorq/Engineering/EngineeringWorkspace', [
            'activeTab' => 'work_orders',
            'workOrders' => $workOrders,
            'technicians' => $technicians,
        ]);
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
