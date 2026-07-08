<?php

namespace Modules\Operations\FrontDesk\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Operations\FrontDesk\Services\EngineeringAvailabilityDependencyService;

class EngineeringAvailabilityDependencyController extends Controller
{
    public function __construct(private readonly EngineeringAvailabilityDependencyService $dependencyService) {}

    public function show(Request $request, string $room): JsonResponse
    {
        return response()->json([
            'engineering_availability' => $this->dependencyService->roomAvailability($request->user(), $room),
        ]);
    }
}
