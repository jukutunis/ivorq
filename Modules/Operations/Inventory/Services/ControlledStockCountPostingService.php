<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementSourceLegEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum;
use Modules\Operations\Inventory\Models\StockCountSession;
use Modules\Operations\Inventory\Models\StockCountLine;
use Modules\Operations\Inventory\Models\InventoryUnit;
use RuntimeException;

class ControlledStockCountPostingService
{
    public function __construct(
        private readonly InventoryLedgerPostingService $ledgerPostingService,
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function approve(StockCountSession $session, string $approverId): StockCountSession
    {
        $user = \Modules\Foundation\User\Models\User::findOrFail($approverId);

        if (!Gate::forUser($user)->check('approve', $session)) {
            throw new RuntimeException('Actor does not have permission to approve stock counts.');
        }

        if ($session->created_by && (string) $session->created_by === (string) $approverId) {
            throw new RuntimeException('Stock count requester cannot approve their own count.');
        }

        $session->status = 'approved';
        $session->approved_by = $approverId;
        $session->save();

        return $session->fresh();
    }

    public function post(StockCountSession $session, string $actorId): StockCountSession
    {
        $user = \Modules\Foundation\User\Models\User::findOrFail($actorId);

        if (!Gate::forUser($user)->check('post', $session)) {
            throw new RuntimeException('Actor does not have permission to post stock counts.');
        }

        if ($session->approved_by && (string) $session->approved_by === (string) $actorId) {
            throw new RuntimeException('Stock count approver cannot post their own approved count.');
        }

        $propertyId = $session->property_id;
        $companyId = DB::table('properties')->where('id', $propertyId)->value('company_id');

        $this->confirmationService->requireValidConfirmation(
            $user,
            'inventory-stock-count-posting',
            $companyId,
            $propertyId
        );

        if ($session->lines->isEmpty()) {
            throw new RuntimeException('Stock count must have at least one line to post.');
        }

        return DB::transaction(function () use ($session, $actorId) {
            $correlationId = (string) Str::ulid();

            foreach ($session->lines as $line) {
                $countedQty = (float) ($line->counted_quantity ?? 0);
                $snapshotQty = (float) ($line->expected_quantity_snapshot ?? 0);
                $variance = $countedQty - $snapshotQty;

                if ($variance == 0) {
                    continue;
                }

                $sourceId = (string) Str::ulid();
                $itemId = $line->item_id;
                $locId = $session->location_id;
                $unitId = $this->defaultUnitId($session->property_id);

                $absVariance = abs($variance);

                if ($variance > 0) {
                    $this->ledgerPostingService->post([
                        'property_id' => $session->property_id,
                        'inventory_item_id' => $itemId,
                        'inventory_location_id' => $locId,
                        'inventory_unit_id' => $unitId,
                        'movement_type' => InventoryMovementTypeEnum::CountVarianceIn,
                        'direction' => InventoryMovementDirectionEnum::In,
                        'source_leg' => InventoryMovementSourceLegEnum::Primary,
                        'quantity' => $absVariance,
                        'source_domain' => 'inventory',
                        'source_type' => StockCountLine::class,
                        'source_id' => $sourceId,
                        'correlation_id' => $correlationId,
                        'idempotency_key' => "sc_post_{$session->id}_{$line->id}",
                        'occurred_at' => now(),
                        'created_by' => $actorId,
                    ]);
                } else {
                    $this->ledgerPostingService->post([
                        'property_id' => $session->property_id,
                        'inventory_item_id' => $itemId,
                        'inventory_location_id' => $locId,
                        'inventory_unit_id' => $unitId,
                        'movement_type' => InventoryMovementTypeEnum::CountVarianceOut,
                        'direction' => InventoryMovementDirectionEnum::Out,
                        'source_leg' => InventoryMovementSourceLegEnum::Primary,
                        'quantity' => $absVariance,
                        'source_domain' => 'inventory',
                        'source_type' => StockCountLine::class,
                        'source_id' => $sourceId,
                        'correlation_id' => $correlationId,
                        'idempotency_key' => "sc_post_{$session->id}_{$line->id}",
                        'occurred_at' => now(),
                        'created_by' => $actorId,
                    ]);
                }
            }

            $session->status = 'posted';
            $session->save();

            return $session->fresh();
        });
    }

    private function defaultUnitId(string $propertyId): string
    {
        $unit = InventoryUnit::where('property_id', $propertyId)->first();
        if (!$unit) {
            throw new RuntimeException('No inventory unit found for property.');
        }
        return $unit->id;
    }
}
