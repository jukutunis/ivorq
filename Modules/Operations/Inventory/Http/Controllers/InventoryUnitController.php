<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Inventory\Http\Requests\StoreUnitRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateUnitRequest;
use Modules\Operations\Inventory\Http\Resources\InventoryUnitResource;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Inventory\Services\InventoryMasterDataService;
use Shared\Services\CurrentPropertyService;

class InventoryUnitController extends Controller
{
    public function __construct(
        private InventoryMasterDataService $masterDataService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', InventoryUnit::class);

        $filters = request()->only(['name', 'is_active']);
        $units   = $this->masterDataService->paginateUnits($filters);

        return Inertia::render('Operations/Inventory/Units/Index', [
            'units'   => InventoryUnitResource::collection($units),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', InventoryUnit::class);

        return Inertia::render('Operations/Inventory/Units/Create');
    }

    public function store(StoreUnitRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $unit = $this->masterDataService->createUnit($data);

        return redirect()->route('operations.inventory.units.show', $unit->id)
            ->with('success', 'Unit created successfully.');
    }

    public function show(string $unit): Response
    {
        $model = $this->masterDataService->findUnit($unit);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Inventory/Units/Show', [
            'unit' => new InventoryUnitResource($model),
        ]);
    }

    public function edit(string $unit): Response
    {
        $model = $this->masterDataService->findUnit($unit);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Inventory/Units/Edit', [
            'unit' => new InventoryUnitResource($model),
        ]);
    }

    public function update(UpdateUnitRequest $request, string $unit): RedirectResponse
    {
        $this->masterDataService->updateUnit($unit, $request->validated());

        return redirect()->route('operations.inventory.units.show', $unit)
            ->with('success', 'Unit updated successfully.');
    }

    public function destroy(string $unit): RedirectResponse
    {
        $model = $this->masterDataService->findUnit($unit);
        $this->authorize('delete', $model);

        $this->masterDataService->deleteUnit($unit);

        return redirect()->route('operations.inventory.units.index')
            ->with('success', 'Unit deleted successfully.');
    }
}
