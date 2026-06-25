<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ivorq\StoreShiftLogRequest;
use App\Http\Requests\Ivorq\UpdateDraftShiftLogRequest;
use App\Http\Requests\Ivorq\AcknowledgeShiftLogRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Logbook\Models\ShiftLog;
use Modules\Operations\Logbook\Policies\ShiftLogPolicy;
use Modules\Operations\Logbook\Services\ShiftLogService;
use Shared\Services\CurrentPropertyService;
use Illuminate\Support\Facades\Gate;

class ShiftLogController extends Controller
{
    private ShiftLogService $service;

    public function __construct(ShiftLogService $service)
    {
        $this->service = $service;
        
        // Dynamically register the policy to avoid altering provider bootstrap
        Gate::policy(ShiftLog::class, ShiftLogPolicy::class);
    }

    public function index(Request $request): Response
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $this->authorize('viewAny', ShiftLog::class);

        $shiftLogs = ShiftLog::with(['creator', 'submitter', 'acknowledgedBy', 'shift', 'department'])
            ->latest()
            ->get();

        $shifts = \Modules\Foundation\Department\Models\Shift::all();
        $departments = \Modules\Foundation\Department\Models\Department::all();

        $myOperationalEntries = \Modules\Operations\Logbook\Models\LogbookEntry::with(['creator', 'submitter', 'department'])
            ->where('property_id', $resolvedPropertyId)
            ->where('created_by', auth()->id())
            ->whereIn('status', [
                \Modules\Operations\Logbook\Enums\LogbookEntryStatusEnum::Draft->value,
                \Modules\Operations\Logbook\Enums\LogbookEntryStatusEnum::Submitted->value,
            ])
            ->latest()
            ->get();

        return Inertia::render('Ivorq/Logbook/ShiftLogWorkspace', [
            'shiftLogs' => $shiftLogs,
            'shifts' => $shifts,
            'departments' => $departments,
            'myOperationalEntries' => $myOperationalEntries,
            'auth_user' => auth()->user(),
        ]);
    }

    public function store(StoreShiftLogRequest $request): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $this->authorize('create', ShiftLog::class);

        $data = $request->validated();
        $log = $this->service->createDraft($data, auth()->id());

        return response()->json([
            'success' => true,
            'log' => $log->load(['creator', 'submitter', 'acknowledgedBy', 'shift', 'department']),
        ], 201);
    }

    public function update(UpdateDraftShiftLogRequest $request, ShiftLog $shiftLog): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        if ($shiftLog->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('update', $shiftLog);

        $data = $request->validated();
        $updated = $this->service->updateDraft($shiftLog, $data, auth()->id());

        return response()->json([
            'success' => true,
            'log' => $updated->load(['creator', 'submitter', 'acknowledgedBy', 'shift', 'department']),
        ]);
    }

    public function submit(Request $request, ShiftLog $shiftLog): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        if ($shiftLog->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('submit', $shiftLog);

        $updated = $this->service->submit($shiftLog, auth()->id());

        return response()->json([
            'success' => true,
            'log' => $updated->load(['creator', 'submitter', 'acknowledgedBy', 'shift', 'department']),
        ]);
    }

    public function acknowledge(AcknowledgeShiftLogRequest $request, ShiftLog $shiftLog): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        if ($shiftLog->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('acknowledge', $shiftLog);

        $updated = $this->service->acknowledge($shiftLog, auth()->id());

        return response()->json([
            'success' => true,
            'log' => $updated->load(['creator', 'submitter', 'acknowledgedBy', 'shift', 'department']),
        ]);
    }
}
