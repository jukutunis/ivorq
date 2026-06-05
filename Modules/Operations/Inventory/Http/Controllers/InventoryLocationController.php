<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Inventory\Enums\LocationTypeEnum;
use Modules\Operations\Inventory\Http\Requests\StoreLocationRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateLocationRequest;
use Modules\Operations\Inventory\Http\Resources\InventoryLocationResource;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Services\InventoryMasterDataService;
use Shared\Services\CurrentPropertyService;

class InventoryLocationController extends Controller
{
    public function __construct(
        private InventoryMasterDataService $masterDataService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', InventoryLocation::class);

        $filters   = request()->only(['name', 'location_type', 'is_active']);
        $locations = $this->masterDataService->paginateLocations($filters);

        return Inertia::render('Operations/Inventory/Locations/Index', [
            'locations'      => InventoryLocationResource::collection($locations),
            'location_types' => array_map(
                fn(LocationTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                LocationTypeEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', InventoryLocation::class);

        return Inertia::render('Operations/Inventory/Locations/Create', [
            'location_types' => array_map(
                fn(LocationTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                LocationTypeEnum::cases()
            ),
        ]);
    }

    public function store(StoreLocationRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $location = $this->masterDataService->createLocation($data);

        return redirect()->route('operations.inventory.locations.show', $location->id)
            ->with('success', 'Location created successfully.');
    }

    public function show(string $location): Response
    {
        $model = $this->masterDataService->findLocation($location);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Inventory/Locations/Show', [
            'location' => new InventoryLocationResource($model),
        ]);
    }

    public function edit(string $location): Response
    {
        $model = $this->masterDataService->findLocation($location);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Inventory/Locations/Edit', [
            'location'       => new InventoryLocationResource($model),
            'location_types' => array_map(
                fn(LocationTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                LocationTypeEnum::cases()
            ),
        ]);
    }

    public function update(UpdateLocationRequest $request, string $location): RedirectResponse
    {
        $this->masterDataService->updateLocation($location, $request->validated());

        return redirect()->route('operations.inventory.locations.show', $location)
            ->with('success', 'Location updated successfully.');
    }

    public function destroy(string $location): RedirectResponse
    {
        $model = $this->masterDataService->findLocation($location);
        $this->authorize('delete', $model);

        $this->masterDataService->deleteLocation($location);

        return redirect()->route('operations.inventory.locations.index')
            ->with('success', 'Location deleted successfully.');
    }
}
