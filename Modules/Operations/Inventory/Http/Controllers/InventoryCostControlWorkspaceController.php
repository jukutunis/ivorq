<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Services\InventoryAvcoCostProjectionService;
use Shared\Services\CurrentPropertyService;

class InventoryCostControlWorkspaceController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        if (!auth()->user()->hasPermissionTo('inventory.cost-control.view')) {
            abort(403, 'Unauthorized.');
        }

        $propertyId = app(CurrentPropertyService::class)->getPropertyId();

        $items = InventoryItem::where('property_id', $propertyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $selectedItemId = $request->query('inventory_item_id');

        $projection = null;

        if ($selectedItemId) {
            $projectionService = app(InventoryAvcoCostProjectionService::class);
            $projection = $projectionService->project($propertyId, $selectedItemId);
        }

        return Inertia::render('Operations/Inventory/InventoryCostControlWorkspace', [
            'items' => $items,
            'projection' => $projection,
            'selectedItemId' => $selectedItemId,
        ]);
    }

    public function project(Request $request): array
    {
        if (!auth()->user()->hasPermissionTo('inventory.cost-control.view')) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'inventory_item_id' => ['required', 'string'],
        ]);

        $propertyId = app(CurrentPropertyService::class)->getPropertyId();

        $item = InventoryItem::where('property_id', $propertyId)
            ->where('id', $validated['inventory_item_id'])
            ->first();

        if (!$item) {
            abort(404, 'Inventory item not found in current property.');
        }

        $projectionService = app(InventoryAvcoCostProjectionService::class);

        return $projectionService->project($propertyId, $validated['inventory_item_id']);
    }
}
