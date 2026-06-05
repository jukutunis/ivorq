<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockBalanceRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockCardRepository;
use Modules\Operations\Inventory\Services\InventoryMasterDataService;

class InventoryDashboardController extends Controller
{
    public function __construct(
        private InventoryMasterDataService $masterDataService,
        private InventoryItemRepository $itemRepository,
        private InventoryStockBalanceRepository $balanceRepository,
        private InventoryStockCardRepository $stockCardRepository,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', InventoryCategory::class);

        $recentMovements = $this->stockCardRepository->recent(10);
        $lowStockItems   = $this->itemRepository->lowStock();
        $outOfStock      = $this->itemRepository->outOfStock();

        return Inertia::render('Operations/Inventory/Dashboard', [
            'recent_movements' => $recentMovements,
            'low_stock_count'  => $lowStockItems->count(),
            'out_of_stock_count' => $outOfStock->count(),
        ]);
    }
}
