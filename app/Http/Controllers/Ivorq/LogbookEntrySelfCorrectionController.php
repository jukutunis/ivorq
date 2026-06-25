<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ivorq\StoreLogbookEntrySelfCorrectionRequest;
use Illuminate\Http\JsonResponse;
use Modules\Operations\Logbook\Models\LogbookEntry;
use Modules\Operations\Logbook\Models\LogbookEntrySelfCorrection;
use Modules\Operations\Logbook\Policies\LogbookEntrySelfCorrectionPolicy;
use Modules\Operations\Logbook\Services\LogbookEntrySelfCorrectionService;
use Shared\Services\CurrentPropertyService;
use Illuminate\Support\Facades\Gate;

class LogbookEntrySelfCorrectionController extends Controller
{
    private LogbookEntrySelfCorrectionService $service;

    public function __construct(LogbookEntrySelfCorrectionService $service)
    {
        $this->service = $service;
        Gate::policy(LogbookEntrySelfCorrection::class, LogbookEntrySelfCorrectionPolicy::class);
    }

    public function append(StoreLogbookEntrySelfCorrectionRequest $request, LogbookEntry $logbookEntry): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        if ($logbookEntry->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $policy = new LogbookEntrySelfCorrectionPolicy();
        if (!$policy->append(auth()->user(), $logbookEntry)) {
            throw new \Illuminate\Auth\Access\AuthorizationException("This action is unauthorized.");
        }

        $data = $request->validated();
        $correction = $this->service->append($logbookEntry->id, $data, auth()->id());

        return response()->json([
            'success' => true,
            'correction' => $correction->load(['corrector']),
            'entry' => $logbookEntry->fresh(['creator', 'submitter', 'department', 'resolution.resolver', 'corrections.corrector']),
        ], 201);
    }
}
