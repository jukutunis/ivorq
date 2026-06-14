<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\CountStatusEnum;
use Modules\Operations\Inventory\Models\StockCountLine;
use Modules\Operations\Inventory\Models\StockCountSession;
use Modules\Operations\Inventory\Repositories\InventoryStockRepository;

class StockCountLineService
{
    public function __construct(
        private InventoryStockRepository $stockRepository
    ) {}

    public function addItems(string $sessionId, array $itemIds): void
    {
        $session = StockCountSession::findOrFail($sessionId);

        if ($session->status !== CountStatusEnum::DRAFT) {
            throw ValidationException::withMessages([
                'status' => ["Cannot add items. Session must be in Draft."],
            ]);
        }

        foreach ($itemIds as $itemId) {
            StockCountLine::firstOrCreate([
                'property_id'            => $session->property_id,
                'stock_count_session_id' => $session->id,
                'item_id'                => $itemId,
            ]);
        }
    }

    public function updateCount(string $lineId, float $countedQty, ?string $reasonCode = null): StockCountLine
    {
        $line = StockCountLine::with('session')->findOrFail($lineId);

        if ($line->session->status !== CountStatusEnum::IN_PROGRESS && $line->session->status !== CountStatusEnum::STALE) {
            throw ValidationException::withMessages([
                'status' => ["Cannot update count. Session must be In Progress or Stale."],
            ]);
        }

        $expected = (float) $line->expected_quantity_snapshot;
        $variance = $countedQty - $expected;

        if ($variance != 0 && empty($reasonCode)) {
            throw ValidationException::withMessages([
                'reason_code' => ["A reason code is required when there is a variance."],
            ]);
        }

        $line->counted_quantity = $countedQty;
        $line->variance_quantity = $variance;
        $line->reason_code = $variance != 0 ? $reasonCode : null;
        $line->save();

        return $line;
    }

    public function revalidate(string $lineId): StockCountLine
    {
        $line = StockCountLine::with('session')->findOrFail($lineId);

        if ($line->session->status !== CountStatusEnum::STALE) {
            throw ValidationException::withMessages([
                'status' => ["Can only revalidate lines when session is Stale."],
            ]);
        }

        // Re-fetch snapshot
        $stock = $this->stockRepository->findOrCreate($line->item_id, $line->session->location_id, $line->session->property_id);
        $expectedQuantity = $stock ? $stock->physical_quantity : 0;

        $line->expected_quantity_snapshot = $expectedQuantity;
        $line->snapshot_timestamp = now();
        $line->counted_quantity = null;
        $line->variance_quantity = null;
        $line->reason_code = null;
        $line->save();

        return $line;
    }
}
