<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\PMS\Enums\StayStatusEnum;
use Modules\Operations\PMS\Models\Stay;
use Modules\Operations\PMS\Repositories\ReservationRepository;

class DashboardController extends Controller
{
    public function __construct(
        private ReservationRepository $reservationRepository,
    ) {}

    public function index(): Response
    {
        $user = auth()->user();

        // ── PMS stats ──────────────────────────────────────────────────────
        $pmsStats = null;

        if ($user?->can('pms.reservation.view')) {
            $pmsStats = [
                'arrivals_today'   => $this->reservationRepository->arrivalsToday()->count(),
                'departures_today' => $this->reservationRepository->departuresToday()->count(),
                'in_house_count'   => Stay::where('status', StayStatusEnum::CheckedIn)->count(),
                'available_rooms'  => Room::where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNull('occupancy_status')
                          ->orWhere('occupancy_status', RoomOccupancyStatusEnum::Vacant);
                    })
                    ->count(),
            ];
        }

        // ── Inventory stats ────────────────────────────────────────────────
        $inventoryStats = null;

        if ($user?->can('inventory.item.view')) {
            $inventoryStats = [
                'total_items'       => InventoryItem::where('is_active', true)->count(),
                'low_stock_items'   => InventoryItem::where('is_active', true)
                    ->whereExists(function ($q) {
                        $q->from('inventory_stock_balances')
                          ->whereColumn('inventory_stock_balances.item_id', 'inventory_items.id')
                          ->whereColumn('inventory_stock_balances.quantity', '<', 'inventory_items.reorder_point');
                    })->count(),
                'draft_receipts'    => $user->can('inventory.receipt.view')
                    ? InventoryReceipt::where('status', ReceiptStatusEnum::Draft)->count()
                    : null,
                'draft_issues'      => $user->can('inventory.issue.view')
                    ? InventoryIssue::where('status', IssueStatusEnum::Draft)->count()
                    : null,
                'pending_transfers' => $user->can('inventory.transfer.view')
                    ? InventoryTransfer::where('status', TransferStatusEnum::Draft)->count()
                    : null,
                'pending_adjustments' => $user->can('inventory.adjustment.view')
                    ? InventoryAdjustment::whereIn('status', [
                        AdjustmentStatusEnum::Draft->value,
                        AdjustmentStatusEnum::Submitted->value,
                      ])->count()
                    : null,
            ];
        }

        return Inertia::render('Dashboard', [
            'pmsStats'       => $pmsStats,
            'inventoryStats' => $inventoryStats,
        ]);
    }
}
