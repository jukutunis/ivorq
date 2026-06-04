<?php

namespace Modules\Operations\Zoning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Zoning\Enums\ZoneStatusEnum;
use Modules\Operations\Zoning\Enums\ZoneTypeEnum;
use Modules\Operations\Zoning\Enums\ZonePriorityEnum;
use Modules\Operations\Zoning\Http\Requests\ChangeZoneStatusRequest;
use Modules\Operations\Zoning\Http\Requests\StoreZoneRequest;
use Modules\Operations\Zoning\Http\Requests\UpdateZoneRequest;
use Modules\Operations\Zoning\Http\Resources\ZoneAssignmentResource;
use Modules\Operations\Zoning\Http\Resources\ZoneHistoryResource;
use Modules\Operations\Zoning\Http\Resources\ZoneResource;
use Modules\Operations\Zoning\Http\Resources\ZoneTemplateResource;
use Modules\Operations\Zoning\Models\Zone;
use Modules\Operations\Zoning\Repositories\ZoneHistoryRepository;
use Modules\Operations\Zoning\Repositories\ZoneTemplateRepository;
use Modules\Operations\Zoning\Services\ZoneService;
use Shared\Services\CurrentPropertyService;

class ZoneController extends Controller
{
    public function __construct(
        private ZoneService            $zoneService,
        private ZoneHistoryRepository  $zoneHistoryRepository,
        private ZoneTemplateRepository $zoneTemplateRepository,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Zone::class);

        $zones = $this->zoneService->paginate();

        return Inertia::render('Operations/Zone/Index', [
            'zones' => ZoneResource::collection($zones),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Zone::class);

        return Inertia::render('Operations/Zone/Create', [
            'zone_types' => array_map(
                fn(ZoneTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                ZoneTypeEnum::cases()
            ),
            'priorities' => array_map(
                fn(ZonePriorityEnum $p) => ['value' => $p->value, 'label' => $p->label()],
                ZonePriorityEnum::cases()
            ),
            'templates'  => ZoneTemplateResource::collection(
                $this->zoneTemplateRepository->all()
            ),
        ]);
    }

    public function store(StoreZoneRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $zone = $this->zoneService->create($data);

        return redirect()->route('operations.zones.show', $zone->id)
            ->with('success', 'Zone created successfully.');
    }

    public function show(string $zone): Response
    {
        $model = $this->zoneService->find($zone);
        $this->authorize('view', $model);

        $histories = $this->zoneHistoryRepository->forZonePaginated($model->id);

        return Inertia::render('Operations/Zone/Show', [
            'zone'        => new ZoneResource($model),
            'assignments' => ZoneAssignmentResource::collection($model->activeAssignments()->with(['user', 'department'])->get()),
            'histories'   => ZoneHistoryResource::collection($histories),
        ]);
    }

    public function edit(string $zone): Response
    {
        $model = $this->zoneService->find($zone);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Zone/Edit', [
            'zone'       => new ZoneResource($model),
            'zone_types' => array_map(
                fn(ZoneTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                ZoneTypeEnum::cases()
            ),
            'priorities' => array_map(
                fn(ZonePriorityEnum $p) => ['value' => $p->value, 'label' => $p->label()],
                ZonePriorityEnum::cases()
            ),
            'statuses'   => array_map(
                fn(ZoneStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                ZoneStatusEnum::cases()
            ),
        ]);
    }

    public function update(UpdateZoneRequest $request, string $zone): RedirectResponse
    {
        $this->zoneService->update($zone, $request->validated());

        return redirect()->route('operations.zones.show', $zone)
            ->with('success', 'Zone updated successfully.');
    }

    public function destroy(string $zone): RedirectResponse
    {
        $model = $this->zoneService->find($zone);
        $this->authorize('delete', $model);

        $this->zoneService->delete($zone);

        return redirect()->route('operations.zones.index')
            ->with('success', 'Zone deleted successfully.');
    }

    public function changeStatus(ChangeZoneStatusRequest $request, string $zone): JsonResponse
    {
        $data    = $request->validated();
        $status  = ZoneStatusEnum::from($data['status']);
        $remarks = $data['remarks'] ?? null;

        $updated = $this->zoneService->changeStatus($zone, $status, $remarks);

        return response()->json([
            'message' => "Zone status changed to {$status->label()}.",
            'zone'    => new ZoneResource($updated),
        ]);
    }
}
