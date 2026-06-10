<?php

namespace Modules\Finance\Banking\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Services\ReconciliationSessionService;
use Illuminate\Http\JsonResponse;

class ReconciliationSessionController extends Controller
{
    public function __construct(
        protected ReconciliationSessionService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ReconciliationSession::class);

        $propertyId = $request->header('X-Property-Id');
        
        $sessions = ReconciliationSession::where('property_id', $propertyId)
            ->withCount('matches')
            ->orderBy('created_at', 'desc')
            ->paginate();

        return response()->json($sessions);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', ReconciliationSession::class);

        $data = $request->validate([
            'bank_account_id' => 'required|string|exists:bank_accounts,id',
            'statement_date_start' => 'required|date',
            'statement_date_end' => 'required|date|after_or_equal:statement_date_start',
            'opening_balance' => 'required|numeric',
        ]);

        $data['property_id'] = $request->header('X-Property-Id');

        try {
            $session = $this->service->create($data);
            return response()->json(['data' => $session], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(Request $request, ReconciliationSession $session): JsonResponse
    {
        Gate::authorize('view', $session);
        $session->load('matches');
        return response()->json(['data' => $session]);
    }

    public function complete(Request $request, ReconciliationSession $session): JsonResponse
    {
        Gate::authorize('manage', $session);

        try {
            $updatedSession = $this->service->complete($session->id, auth()->id());
            return response()->json(['data' => $updatedSession]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, ReconciliationSession $session): JsonResponse
    {
        Gate::authorize('manage', $session);

        try {
            $updatedSession = $this->service->cancel($session->id, auth()->id());
            return response()->json(['data' => $updatedSession]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, ReconciliationSession $session): JsonResponse
    {
        Gate::authorize('manage', $session);

        try {
            $this->service->delete($session);
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
