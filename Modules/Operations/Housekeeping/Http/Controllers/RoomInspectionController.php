<?php

namespace Modules\Operations\Housekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Enums\InspectionTypeEnum;
use Modules\Operations\Housekeeping\Http\Requests\FailInspectionRequest;
use Modules\Operations\Housekeeping\Http\Requests\PassInspectionRequest;
use Modules\Operations\Housekeeping\Http\Requests\ConfirmInspectionPassRequest;
use Modules\Operations\Housekeeping\Http\Requests\ConductInspectionRequest;
use Modules\Operations\Housekeeping\Http\Requests\StoreRoomInspectionRequest;
use Modules\Operations\Housekeeping\Http\Requests\UpdateRoomInspectionRequest;
use Modules\Operations\Housekeeping\Http\Resources\RoomInspectionResource;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Repositories\InspectionRepository;
use Modules\Operations\Housekeeping\Services\InspectionService;
use Modules\Operations\Housekeeping\Services\HousekeepingCleaningInspectionReadinessLifecycleService;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class RoomInspectionController extends Controller
{
    public function __construct(
        private InspectionService    $inspectionService,
        private InspectionRepository $inspectionRepository,
        private HousekeepingCleaningInspectionReadinessLifecycleService $lifecycle,
    ) {}

    public function index(): Response
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = request()->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $this->authorize('viewAny', RoomInspection::class);

        $inspections = $this->inspectionService->paginate();

        return Inertia::render('Operations/Housekeeping/Inspections/Index', [
            'inspections'      => RoomInspectionResource::collection($inspections),
            'inspection_types' => array_map(
                fn(InspectionTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                InspectionTypeEnum::cases()
            ),
            'statuses' => array_map(
                fn(InspectionStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                InspectionStatusEnum::cases()
            ),
        ]);
    }

    public function create(): Response
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = request()->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $this->authorize('create', RoomInspection::class);

        return Inertia::render('Operations/Housekeeping/Inspections/Create', [
            'inspection_types' => array_map(
                fn(InspectionTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                InspectionTypeEnum::cases()
            ),
        ]);
    }

    public function store(StoreRoomInspectionRequest $request)
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        if ($request->has('property_id') && $request->input('property_id') !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $validated = $request->validated();
        $data      = array_merge($validated, [
            'property_id'  => $resolvedPropertyId,
            'supervisor_id' => auth()->id(),
        ]);

        $inspection = $this->inspectionService->create($data);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'inspection' => new RoomInspectionResource($inspection->fresh())
            ], 201);
        }

        return redirect()->route('operations.inspections.show', $inspection->id)
            ->with('success', 'Inspection created successfully.');
    }

    public function show(\Illuminate\Http\Request $request, string $inspection): Response
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $model = $this->inspectionService->find($inspection);
        if ($model->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('view', $model);

        $passContext = null;
        if (
            $model->status === InspectionStatusEnum::InProgress
            && $request->user()->can('conduct', $model)
        ) {
            try {
                $passContext = $this->lifecycle->inspectionPassContext($request->user(), $model->id);
            } catch (DomainException|HttpException) {
                $passContext = null;
            }
        }

        return Inertia::render('Operations/Housekeeping/Inspections/Show', [
            'inspection' => new RoomInspectionResource($model),
            'severities' => array_map(
                fn(InspectionSeverityEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                InspectionSeverityEnum::cases()
            ),
            'pass_context' => $passContext,
        ]);
    }

    public function edit(\Illuminate\Http\Request $request, string $inspection): Response
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $model = $this->inspectionService->find($inspection);
        if ($model->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('update', $model);

        return Inertia::render('Operations/Housekeeping/Inspections/Edit', [
            'inspection'       => new RoomInspectionResource($model),
            'inspection_types' => array_map(
                fn(InspectionTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                InspectionTypeEnum::cases()
            ),
        ]);
    }

    public function update(UpdateRoomInspectionRequest $request, string $inspection)
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        if ($request->has('property_id') && $request->input('property_id') !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $model = $this->inspectionService->find($inspection);
        if ($model->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('update', $model);

        $this->inspectionRepository->update($inspection, $request->validated());

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Inspection updated successfully.'
            ]);
        }

        return redirect()->route('operations.inspections.show', $inspection)
            ->with('success', 'Inspection updated successfully.');
    }

    public function destroy(\Illuminate\Http\Request $request, string $inspection)
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $model = $this->inspectionService->find($inspection);
        if ($model->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('delete', $model);

        $model->delete();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Inspection deleted successfully.'
            ]);
        }

        return redirect()->route('operations.inspections.index')
            ->with('success', 'Inspection deleted successfully.');
    }

    public function conduct(ConductInspectionRequest $request, string $inspection)
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $model = $this->inspectionService->find($inspection);
        if ($model->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('conduct', $model);

        try {
            $this->inspectionService->conduct($inspection, $request->user());
        } catch (DomainException $exception) {
            return $this->boundedLifecycleResponse($request, $exception->getMessage(), 422, $inspection);
        } catch (HttpException $exception) {
            return $this->boundedLifecycleResponse($request, $exception->getMessage(), $exception->getStatusCode(), $inspection);
        } catch (Throwable) {
            return $this->boundedLifecycleResponse($request, 'HOUSEKEEPING_LIFECYCLE_ACTION_FAILED', 500, $inspection);
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Inspection started.',
                'inspection' => new RoomInspectionResource($model->fresh())
            ]);
        }

        return redirect()->route('operations.inspections.show', $inspection)
            ->with('success', 'Inspection started.');
    }

    public function pass(PassInspectionRequest $request, string $inspection): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $model = $this->inspectionService->find($inspection);
        if ($model->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('conduct', $model);

        $data     = $request->validated();
        $severity = isset($data['inspection_severity'])
            ? InspectionSeverityEnum::from($data['inspection_severity'])
            : null;

        try {
            $updated = $this->lifecycle->passInspection(
                $request->user(),
                $inspection,
                $data['release_reason'],
                $severity,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (HttpException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (Throwable) {
            return response()->json(['message' => 'HOUSEKEEPING_LIFECYCLE_ACTION_FAILED'], 500);
        }

        return response()->json([
            'message'     => 'Inspection passed.',
            'inspection'  => new RoomInspectionResource($updated->fresh(['room', 'task'])),
        ]);
    }

    public function fail(FailInspectionRequest $request, string $inspection): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $model = $this->inspectionService->find($inspection);
        if ($model->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('conduct', $model);

        $data     = $request->validated();
        $severity = isset($data['inspection_severity'])
            ? InspectionSeverityEnum::from($data['inspection_severity'])
            : null;

        try {
            $updated = $this->lifecycle->failInspection(
                $request->user(),
                $inspection,
                $data['remarks'],
                $severity,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (HttpException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (Throwable) {
            return response()->json(['message' => 'HOUSEKEEPING_LIFECYCLE_ACTION_FAILED'], 500);
        }

        return response()->json([
            'message'     => 'Inspection failed.',
            'inspection'  => new RoomInspectionResource($updated->fresh(['room', 'task'])),
        ]);
    }

    public function confirmPass(ConfirmInspectionPassRequest $request, string $inspection): JsonResponse
    {
        $data = $request->validated();

        try {
            $context = $this->lifecycle->confirmInspectionPass(
                $request->user(),
                $inspection,
                $data['release_reason'],
                $data['password'],
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (HttpException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (Throwable) {
            return response()->json(['message' => 'HOUSEKEEPING_CONFIRMATION_FAILED'], 500);
        }

        return response()->json([
            'message' => 'Room release confirmation recorded.',
            'release_context' => $context,
        ]);
    }

    private function boundedLifecycleResponse(
        \Illuminate\Http\Request $request,
        string $message,
        int $status,
        string $inspection,
    ) {
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['message' => $message], $status);
        }

        return redirect()->route('operations.inspections.show', $inspection)->with('error', $message);
    }
}
