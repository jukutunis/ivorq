<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ivorq\StoreLogbookEntryFollowUpResolutionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Operations\Logbook\Models\LogbookEntry;
use Modules\Operations\Logbook\Models\LogbookEntryFollowUpResolution;
use Modules\Operations\Logbook\Policies\LogbookEntryFollowUpResolutionPolicy;
use Modules\Operations\Logbook\Services\LogbookEntryFollowUpResolutionService;
use Shared\Services\CurrentPropertyService;
use Illuminate\Support\Facades\Gate;

class LogbookEntryFollowUpResolutionController extends Controller
{
    private LogbookEntryFollowUpResolutionService $service;

    public function __construct(LogbookEntryFollowUpResolutionService $service)
    {
        $this->service = $service;
        Gate::policy(LogbookEntryFollowUpResolution::class, LogbookEntryFollowUpResolutionPolicy::class);
    }

    public function resolve(StoreLogbookEntryFollowUpResolutionRequest $request, LogbookEntry $logbookEntry): JsonResponse
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

        $policy = new LogbookEntryFollowUpResolutionPolicy();
        if (!$policy->resolve(auth()->user(), $logbookEntry)) {
            throw new \Illuminate\Auth\Access\AuthorizationException("This action is unauthorized.");
        }

        $data = $request->validated();
        $resolution = $this->service->resolve($logbookEntry->id, $data, auth()->id());

        return response()->json([
            'success' => true,
            'resolution' => $resolution->load(['resolver']),
            'entry' => $logbookEntry->fresh(['creator', 'submitter', 'department', 'resolution.resolver']),
        ], 201);
    }
}
