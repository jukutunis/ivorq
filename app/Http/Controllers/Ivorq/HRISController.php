<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class HRISController extends Controller
{
    public function attendance()
    {
        return Inertia::render('Ivorq/HRIS/HRISWorkspace', ['activeTab' => 'attendance']);
    }

    public function shiftCoverage()
    {
        return Inertia::render('Ivorq/HRIS/HRISWorkspace', ['activeTab' => 'shift_coverage']);
    }

    public function leaveRequests()
    {
        return Inertia::render('Ivorq/HRIS/HRISWorkspace', ['activeTab' => 'leave_requests']);
    }

    public function payroll()
    {
        return Inertia::render('Ivorq/HRIS/HRISWorkspace', ['activeTab' => 'payroll']);
    }
}
