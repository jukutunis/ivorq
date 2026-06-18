<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\CountStatusEnum;
use Modules\Operations\Inventory\Models\StockCountSession;
use Modules\Operations\Inventory\Repositories\InventoryStockRepository;
use Modules\Foundation\Approval\Services\ApprovalEngineService;

class StockCountSessionService
{
    public function __construct(
        private InventoryStockRepository $stockRepository,
        private ApprovalEngineService $approvalEngineService
    ) {}

    public function create(array $data): StockCountSession
    {
        $session = new StockCountSession($data);
        $session->status = CountStatusEnum::DRAFT->value;
        $session->save();
        return $session;
    }

    public function startCount(string $id): StockCountSession
    {
        $session = StockCountSession::with('lines')->findOrFail($id);

        if ($session->status !== CountStatusEnum::DRAFT) {
            throw ValidationException::withMessages([
                'status' => ["Cannot start count. Session is not in Draft."],
            ]);
        }

        DB::transaction(function () use ($session) {
            // Execute Snapshot Strategy
            foreach ($session->lines as $line) {
                // Unlocked read for snapshot
                $stock = $this->stockRepository->findOrCreate($line->item_id, $session->location_id, $session->property_id);
                $expectedQuantity = $stock ? $stock->physical_quantity : 0;

                $line->expected_quantity_snapshot = $expectedQuantity;
                $line->snapshot_timestamp = now();
                $line->save();
            }

            $session->status = CountStatusEnum::IN_PROGRESS->value;
            $session->started_at = now();
            $session->save();
        });

        return $session->fresh();
    }

    public function submit(string $id, ?string $userId = null): StockCountSession
    {
        $session = StockCountSession::with('lines')->findOrFail($id);

        if ($session->status !== CountStatusEnum::IN_PROGRESS && $session->status !== CountStatusEnum::STALE) {
            throw ValidationException::withMessages([
                'status' => ["Cannot submit. Session must be In Progress or Stale."],
            ]);
        }

        // Session-Level Lock & Staleness Detection
        DB::transaction(function () use ($session, $userId) {
            foreach ($session->lines as $line) {
                // Staleness check against true system stock
                $currentStock = $this->stockRepository->findOrCreateLocked($line->item_id, $session->location_id, $session->property_id);
                $currentQty = $currentStock ? $currentStock->physical_quantity : 0;

                if ((float) $currentQty !== (float) $line->expected_quantity_snapshot) {
                    $session->status = CountStatusEnum::STALE->value;
                    $session->save();
                    throw ValidationException::withMessages([
                        'staleness' => ["Staleness detected for item {$line->item_id}. Session moved to STALE."],
                    ]);
                }
            }

            // Lock engaged by moving to SUBMITTED
            $session->status = CountStatusEnum::SUBMITTED->value;
            $session->submitted_by = $userId ?? auth()->id();
            $session->save();

            // Dispatch Foundation Approval Engine workflow event
            $this->approvalEngineService->submitForApproval($session, $session->submitted_by);
        });

        return $session->fresh();
    }


    public function cancel(string $id): StockCountSession
    {
        $session = StockCountSession::findOrFail($id);

        $session->status = CountStatusEnum::CANCELLED->value;
        $session->save();

        return $session;
    }
}
