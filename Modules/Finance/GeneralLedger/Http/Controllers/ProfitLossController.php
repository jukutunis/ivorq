<?php

namespace Modules\Finance\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Finance\GeneralLedger\Services\ProfitLossService;

class ProfitLossController extends Controller
{
    public function __construct(protected ProfitLossService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'property_id' => 'required|string|size:26',
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $dto = $this->service->generate(
            $request->property_id,
            (int) $request->year,
            (int) $request->month
        );

        return response()->json($dto);
    }
}
