<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementSourceLegEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryAdjustmentLine;
use Modules\Operations\Inventory\Models\InventoryUnit;
use RuntimeException;

class ControlledAdjustmentPostingService
{
    public function __construct(
        private readonly InventoryLedgerPostingService $ledgerPostingService,
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function approve(InventoryAdjustment $adjustment, string $approverId): InventoryAdjustment
    {
        $user = \Modules\Foundation\User\Models\User::findOrFail($approverId);

        if (!Gate::forUser($user)->check('approve', $adjustment)) {
            throw new RuntimeException('Actor does not have permission to approve adjustments.');
        }

        if ($adjustment->created_by && (string) $adjustment->created_by === (string) $approverId) {
            throw new RuntimeException('Adjustment requester cannot approve their own adjustment.');
        }

        if ($adjustment->lines->isEmpty()) {
            throw new RuntimeException('Adjustment must have at least one line before approval.');
        }

        $adjustment->status = 'approved';
        $adjustment->approved_by = $approverId;
        $adjustment->approved_at = now();
        $adjustment->save();

        return $adjustment->fresh();
    }

    public function post(InventoryAdjustment $adjustment, string $actorId): InventoryAdjustment
    {
        $user = \Modules\Foundation\User\Models\User::findOrFail($actorId);

        if (!Gate::forUser($user)->check('post', $adjustment)) {
            throw new RuntimeException('Actor does not have permission to post adjustments.');
        }

        if ($adjustment->approved_by && (string) $adjustment->approved_by === (string) $actorId) {
            throw new RuntimeException('Adjustment approver cannot post their own approved adjustment.');
        }

        $propertyId = $adjustment->property_id;
        $companyId = DB::table('properties')->where('id', $propertyId)->value('company_id');

        $this->confirmationService->requireValidConfirmation(
            $user,
            'inventory-adjustment-posting',
            $companyId,
            $propertyId
        );

        if ($adjustment->lines->isEmpty()) {
            throw new RuntimeException('Adjustment must have at least one line to post.');
        }

        return DB::transaction(function () use ($adjustment, $actorId) {
            $correlationId = (string) Str::ulid();

            foreach ($adjustment->lines as $line) {
                $qty = (float) ($line->quantity_variance ?? 0);

                if ($qty == 0) {
                    continue;
                }

                $sourceId = (string) Str::ulid();
                $itemId = $line->item_id;
                $locId = $adjustment->location_id;
                $unitId = $this->defaultUnitId($adjustment->property_id);

                $absQty = abs($qty);

                if ($qty > 0) {
                    $movementType = InventoryMovementTypeEnum::ManualAdjustmentIn;
                    $direction = InventoryMovementDirectionEnum::In;
                } else {
                    $movementType = InventoryMovementTypeEnum::ManualAdjustmentOut;
                    $direction = InventoryMovementDirectionEnum::Out;
                }

                $this->ledgerPostingService->post([
                    'property_id' => $adjustment->property_id,
                    'inventory_item_id' => $itemId,
                    'inventory_location_id' => $locId,
                    'inventory_unit_id' => $unitId,
                    'movement_type' => $movementType,
                    'direction' => $direction,
                    'source_leg' => InventoryMovementSourceLegEnum::Primary,
                    'quantity' => $absQty,
                    'source_domain' => 'inventory',
                    'source_type' => InventoryAdjustmentLine::class,
                    'source_id' => $sourceId,
                    'correlation_id' => $correlationId,
                    'idempotency_key' => "adj_post_{$adjustment->id}_{$line->id}",
                    'occurred_at' => now(),
                    'created_by' => $actorId,
                ]);
            }

            return $adjustment;
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
