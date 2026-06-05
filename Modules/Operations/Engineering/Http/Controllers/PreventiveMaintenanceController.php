<?php

namespace Modules\Operations\Engineering\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Engineering\Enums\PmFrequencyEnum;
use Modules\Operations\Engineering\Enums\PmStatusEnum;
use Modules\Operations\Engineering\Http\Requests\GeneratePreventiveMaintenanceTaskRequest;
use Modules\Operations\Engineering\Http\Requests\StorePreventiveMaintenanceRequest;
use Modules\Operations\Engineering\Http\Requests\UpdatePreventiveMaintenanceRequest;
use Modules\Operations\Engineering\Http\Resources\PreventiveMaintenanceResource;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;
use Modules\Operations\Engineering\Services\PreventiveMaintenanceService;
use Shared\Services\CurrentPropertyService;

class PreventiveMaintenanceController extends Controller
{
    public function __construct(
        private PreventiveMaintenanceService $pmService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', PreventiveMaintenance::class);

        $filters = request()->only(['status', 'frequency']);
        $pms     = $this->pmService->paginate($filters);

        return Inertia::render('Operations/Engineering/PreventiveMaintenances/Index', [
            'preventive_maintenances' => PreventiveMaintenanceResource::collection($pms),
            'statuses'                => array_map(
                fn(PmStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                PmStatusEnum::cases()
            ),
            'frequencies' => array_map(
                fn(PmFrequencyEnum $f) => ['value' => $f->value, 'label' => $f->label()],
                PmFrequencyEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', PreventiveMaintenance::class);

        return Inertia::render('Operations/Engineering/PreventiveMaintenances/Create', [
            'frequencies' => array_map(
                fn(PmFrequencyEnum $f) => ['value' => $f->value, 'label' => $f->label()],
                PmFrequencyEnum::cases()
            ),
        ]);
    }

    public function store(StorePreventiveMaintenanceRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $pm = $this->pmService->create($data);

        return redirect()->route('operations.preventive-maintenances.show', $pm->id)
            ->with('success', 'Preventive maintenance program created successfully.');
    }

    public function show(string $pm): Response
    {
        $model = $this->pmService->find($pm);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Engineering/PreventiveMaintenances/Show', [
            'preventive_maintenance' => new PreventiveMaintenanceResource($model->loadCount('tasks')),
        ]);
    }

    public function edit(string $pm): Response
    {
        $model = $this->pmService->find($pm);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Engineering/PreventiveMaintenances/Edit', [
            'preventive_maintenance' => new PreventiveMaintenanceResource($model),
            'frequencies'            => array_map(
                fn(PmFrequencyEnum $f) => ['value' => $f->value, 'label' => $f->label()],
                PmFrequencyEnum::cases()
            ),
            'statuses' => array_map(
                fn(PmStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                PmStatusEnum::cases()
            ),
        ]);
    }

    public function update(UpdatePreventiveMaintenanceRequest $request, string $pm): RedirectResponse
    {
        $this->pmService->update($pm, $request->validated());

        return redirect()->route('operations.preventive-maintenances.show', $pm)
            ->with('success', 'Preventive maintenance program updated successfully.');
    }

    public function destroy(string $pm): RedirectResponse
    {
        $model = $this->pmService->find($pm);
        $this->authorize('delete', $model);

        $this->pmService->delete($pm);

        return redirect()->route('operations.preventive-maintenances.index')
            ->with('success', 'Preventive maintenance program deleted successfully.');
    }

    public function generateTask(GeneratePreventiveMaintenanceTaskRequest $request, string $pm): RedirectResponse
    {
        $data          = $request->validated();
        $scheduledDate = isset($data['scheduled_date']) ? Carbon::parse($data['scheduled_date']) : null;

        $this->pmService->generateTask($pm, $scheduledDate);

        return redirect()->route('operations.preventive-maintenances.show', $pm)
            ->with('success', 'PM task generated successfully.');
    }
}
