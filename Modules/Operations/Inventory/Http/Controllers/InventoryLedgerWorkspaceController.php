<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Operations\Inventory\Models\InventoryStockMovement;

class InventoryLedgerWorkspaceController extends Controller
{
    public function index(): InertiaResponse
    {
        if (!auth()->user()->can('viewAny', InventoryStockMovement::class)) {
            abort(403, 'Unauthorized.');
        }

        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();

        $movements = InventoryStockMovement::query()
            ->with(['item', 'location', 'unit', 'createdBy'])
            ->orderByDesc('occurred_at')
            ->paginate(25);

        $stockOnHand = InventoryStockMovement::query()
            ->selectRaw('inventory_item_id, inventory_location_id, inventory_unit_id, SUM(quantity) as controlled_quantity')
            ->groupBy('inventory_item_id', 'inventory_location_id', 'inventory_unit_id')
            ->with(['item', 'location', 'unit'])
            ->havingRaw('SUM(quantity) > 0')
            ->get();

        return Inertia::render('Operations/Inventory/InventoryLedgerWorkspace', [
            'movements' => $movements,
            'stockOnHand' => $stockOnHand,
            'movementTypes' => [
                ['value' => 'GOODS_RECEIPT', 'label' => 'Goods Receipt'],
            ],
        ]);
    }
}
