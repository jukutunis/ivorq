<?php

namespace Modules\Operations\PMS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Operations\PMS\Http\Requests\CheckInRequest;
use Modules\Operations\PMS\Http\Requests\CheckOutRequest;
use Modules\Operations\PMS\Http\Resources\StayResource;
use Modules\Operations\PMS\Services\FrontDeskService;

class FrontDeskController extends Controller
{
    public function __construct(
        private FrontDeskService $frontDeskService,
    ) {}

    public function checkIn(CheckInRequest $request, string $reservation): JsonResponse
    {
        $stay = $this->frontDeskService->checkIn($reservation);

        return response()->json([
            'message' => 'Guest checked in successfully.',
            'stay'    => new StayResource($stay),
        ]);
    }

    public function checkOut(CheckOutRequest $request, string $stay): JsonResponse
    {
        $updated = $this->frontDeskService->checkOut($stay);

        return response()->json([
            'message' => 'Guest checked out successfully.',
            'stay'    => new StayResource($updated),
        ]);
    }
}
