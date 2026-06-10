<?php

namespace Modules\Finance\Banking\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Services\AutoMatchingService;
use Illuminate\Http\JsonResponse;

class AutoMatchingController extends Controller
{
    public function __construct(
        protected AutoMatchingService $service
    ) {}

    public function generate(Request $request, ReconciliationSession $session): JsonResponse
    {
        Gate::authorize('manage', $session);

        if (in_array($session->status->value, ['Completed', 'Cancelled'])) {
            return response()->json(['message' => 'Cannot run auto-match on a ' . $session->status->value . ' session.'], 422);
        }

        try {
            $recommendations = $this->service->getRecommendations($session);
            return response()->json(['data' => $recommendations]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
