<?php

namespace Modules\Finance\Banking\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Services\ReconciliationMatchService;
use Illuminate\Http\JsonResponse;

class ReconciliationMatchController extends Controller
{
    public function __construct(
        protected ReconciliationMatchService $service
    ) {}

    public function store(Request $request, ReconciliationSession $session): JsonResponse
    {
        Gate::authorize('manage', $session);

        $data = $request->validate([
            'matches' => 'required|array',
            'matches.*.bank_statement_line_id' => 'required|string',
            'matches.*.matchable_type' => 'required|string',
            'matches.*.matchable_id' => 'required|string',
        ]);

        try {
            $matches = $this->service->storeMatches($session, $data['matches'], auth()->id());
            return response()->json(['data' => $matches], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
