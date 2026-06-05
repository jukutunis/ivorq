<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Inventory\Http\Requests\StoreItemRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateItemRequest;
use Modules\Operations\Inventory\Http\Resources\InventoryItemResource;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Services\InventoryMasterDataService;
use Shared\Services\CurrentPropertyService;

class InventoryItemController extends Controller
{
    public function __construct(
        private InventoryMasterDataService $masterDataService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', InventoryItem::class);

        $filters = request()->only(['category_id', 'unit_id', 'is_active', 'name']);
        $items   = $this->masterDataService->paginateItems($filters);

        return Inertia::render('Operations/Inventory/Items/Index', [
            'items'   => InventoryItemResource::collection($items),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', InventoryItem::class);

        return Inertia::render('Operations/Inventory/Items/Create', [
            'categories' => $this->masterDataService->paginateCategories([], 500)->items(),
            'units'      => $this->masterDataService->paginateUnits([], 500)->items(),
        ]);
    }

    public function store(StoreItemRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $item = $this->masterDataService->createItem($data);

        return redirect()->route('operations.inventory.items.show', $item->id)
            ->with('success', 'Item created successfully.');
    }

    public function show(string $item): Response
    {
        $model = $this->masterDataService->findItem($item);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Inventory/Items/Show', [
            'item' => new InventoryItemResource($model),
        ]);
    }

    public function edit(string $item): Response
    {
        $model = $this->masterDataService->findItem($item);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Inventory/Items/Edit', [
            'item'       => new InventoryItemResource($model),
            'categories' => $this->masterDataService->paginateCategories([], 500)->items(),
            'units'      => $this->masterDataService->paginateUnits([], 500)->items(),
        ]);
    }

    public function update(UpdateItemRequest $request, string $item): RedirectResponse
    {
        $this->masterDataService->updateItem($item, $request->validated());

        return redirect()->route('operations.inventory.items.show', $item)
            ->with('success', 'Item updated successfully.');
    }

    public function destroy(string $item): RedirectResponse
    {
        $model = $this->masterDataService->findItem($item);
        $this->authorize('delete', $model);

        $this->masterDataService->deleteItem($item);

        return redirect()->route('operations.inventory.items.index')
            ->with('success', 'Item deleted successfully.');
    }
}
