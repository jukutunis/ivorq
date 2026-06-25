<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ivorq\StoreLogbookEntryRequest;
use App\Http\Requests\Ivorq\UpdateDraftLogbookEntryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Operations\Logbook\Models\LogbookEntry;
use Modules\Operations\Logbook\Policies\LogbookEntryPolicy;
use Modules\Operations\Logbook\Services\LogbookEntryService;
use Modules\Operations\Logbook\Enums\LogbookEntryStatusEnum;
use Shared\Services\CurrentPropertyService;
use Illuminate\Support\Facades\Gate;

class LogbookEntryController extends Controller
{
    private LogbookEntryService $service;

    public function __construct(LogbookEntryService $service)
    {
        $this->service = $service;
        
        // Dynamically register the policy to avoid altering provider bootstrap
        Gate::policy(LogbookEntry::class, LogbookEntryPolicy::class);
    }

    public function index(Request $request): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $this->authorize('viewAny', LogbookEntry::class);

        $entries = LogbookEntry::with(['creator', 'submitter', 'department', 'resolution.resolver'])
            ->where('property_id', $resolvedPropertyId)
            ->where('created_by', auth()->id())
            ->whereIn('status', [
                LogbookEntryStatusEnum::Draft->value,
                LogbookEntryStatusEnum::Submitted->value,
            ])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'entries' => $entries,
        ]);
    }

    public function store(StoreLogbookEntryRequest $request): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $this->authorize('create', LogbookEntry::class);

        $data = $request->validated();
        $entry = $this->service->createDraft($data, auth()->id());

        return response()->json([
            'success' => true,
            'entry' => $entry->load(['creator', 'submitter', 'department', 'resolution.resolver']),
        ], 201);
    }

    public function update(UpdateDraftLogbookEntryRequest $request, LogbookEntry $logbookEntry): JsonResponse
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

        $this->authorize('update', $logbookEntry);

        $data = $request->validated();
        $updated = $this->service->updateDraft($logbookEntry, $data, auth()->id());

        return response()->json([
            'success' => true,
            'entry' => $updated->load(['creator', 'submitter', 'department', 'resolution.resolver']),
        ]);
    }

    public function submit(Request $request, LogbookEntry $logbookEntry): JsonResponse
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

        $this->authorize('submit', $logbookEntry);

        $updated = $this->service->submit($logbookEntry, auth()->id());

        return response()->json([
            'success' => true,
            'entry' => $updated->load(['creator', 'submitter', 'department', 'resolution.resolver']),
        ]);
    }
}
