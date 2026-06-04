<?php

namespace Modules\Operations\Housekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Enums\InspectionTypeEnum;
use Modules\Operations\Housekeeping\Http\Requests\FailInspectionRequest;
use Modules\Operations\Housekeeping\Http\Requests\PassInspectionRequest;
use Modules\Operations\Housekeeping\Http\Requests\StoreRoomInspectionRequest;
use Modules\Operations\Housekeeping\Http\Requests\UpdateRoomInspectionRequest;
use Modules\Operations\Housekeeping\Http\Resources\RoomInspectionResource;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Repositories\InspectionRepository;
use Modules\Operations\Housekeeping\Services\InspectionService;
use Shared\Services\CurrentPropertyService;

class RoomInspectionController extends Controller
{
    public function __construct(
        private InspectionService    $inspectionService,
        private InspectionRepository $inspectionRepository,
    ) {}

    public function index(): Response
    {
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
        $this->authorize('create', RoomInspection::class);

        return Inertia::render('Operations/Housekeeping/Inspections/Create', [
            'inspection_types' => array_map(
                fn(InspectionTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                InspectionTypeEnum::cases()
            ),
        ]);
    }

    public function store(StoreRoomInspectionRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $data      = array_merge($validated, [
            'property_id'  => app(CurrentPropertyService::class)->getId(),
            'inspector_id' => $validated['inspector_id'] ?? auth()->id(),
        ]);

        $inspection = $this->inspectionService->create($data);

        return redirect()->route('operations.inspections.show', $inspection->id)
            ->with('success', 'Inspection created successfully.');
    }

    public function show(string $inspection): Response
    {
        $model = $this->inspectionService->find($inspection);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Housekeeping/Inspections/Show', [
            'inspection' => new RoomInspectionResource($model),
            'severities' => array_map(
                fn(InspectionSeverityEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                InspectionSeverityEnum::cases()
            ),
        ]);
    }

    public function edit(string $inspection): Response
    {
        $model = $this->inspectionService->find($inspection);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Housekeeping/Inspections/Edit', [
            'inspection'       => new RoomInspectionResource($model),
            'inspection_types' => array_map(
                fn(InspectionTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                InspectionTypeEnum::cases()
            ),
        ]);
    }

    public function update(UpdateRoomInspectionRequest $request, string $inspection): RedirectResponse
    {
        $this->inspectionRepository->update($inspection, $request->validated());

        return redirect()->route('operations.inspections.show', $inspection)
            ->with('success', 'Inspection updated successfully.');
    }

    public function destroy(string $inspection): RedirectResponse
    {
        $model = $this->inspectionService->find($inspection);
        $this->authorize('delete', $model);

        $model->delete();

        return redirect()->route('operations.inspections.index')
            ->with('success', 'Inspection deleted successfully.');
    }

    public function conduct(string $inspection): RedirectResponse
    {
        $model = $this->inspectionService->find($inspection);
        $this->authorize('conduct', $model);

        $this->inspectionService->conduct($inspection);

        return redirect()->route('operations.inspections.show', $inspection)
            ->with('success', 'Inspection started.');
    }

    public function pass(PassInspectionRequest $request, string $inspection): JsonResponse
    {
        $data     = $request->validated();
        $severity = isset($data['inspection_severity'])
            ? InspectionSeverityEnum::from($data['inspection_severity'])
            : null;

        $updated = $this->inspectionService->pass($inspection, $data['remarks'] ?? null, $severity);

        return response()->json([
            'message'     => 'Inspection passed.',
            'inspection'  => new RoomInspectionResource($updated),
        ]);
    }

    public function fail(FailInspectionRequest $request, string $inspection): JsonResponse
    {
        $data     = $request->validated();
        $severity = isset($data['inspection_severity'])
            ? InspectionSeverityEnum::from($data['inspection_severity'])
            : null;

        $updated = $this->inspectionService->fail($inspection, $data['remarks'] ?? null, $severity);

        return response()->json([
            'message'     => 'Inspection failed.',
            'inspection'  => new RoomInspectionResource($updated),
        ]);
    }
}
