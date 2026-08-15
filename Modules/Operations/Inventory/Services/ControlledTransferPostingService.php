<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementSourceLegEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Models\InventoryTransferLine;
use Modules\Operations\Inventory\Models\InventoryUnit;
use RuntimeException;

class ControlledTransferPostingService
{
    public function __construct(
        private readonly InventoryLedgerPostingService $ledgerPostingService,
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function post(InventoryTransfer $transfer, string $actorId): InventoryTransfer
    {
        $user = \Modules\Foundation\User\Models\User::findOrFail($actorId);

        if (!Gate::forUser($user)->check('post', $transfer)) {
            throw new RuntimeException('Actor does not have permission to post transfers.');
        }

        $propertyId = $transfer->property_id;
        $companyId = DB::table('properties')->where('id', $propertyId)->value('company_id');

        $this->confirmationService->requireValidConfirmation(
            $user,
            'inventory-transfer-posting',
            $companyId,
            $propertyId
        );

        if ($transfer->lines->isEmpty()) {
            throw new RuntimeException('Transfer must have at least one line to post.');
        }

        return DB::transaction(function () use ($transfer, $actorId) {
            $correlationId = (string) Str::ulid();
            $unitId = $this->defaultUnitId($transfer->property_id);

            foreach ($transfer->lines as $line) {
                $itemId = $line->item_id;
                $fromLocId = $transfer->from_location_id;
                $toLocId = $transfer->to_location_id;

                if (empty($fromLocId) || empty($toLocId)) {
                    throw new RuntimeException('Transfer must specify source and destination locations.');
                }

                $qty = (float) ($line->quantity_requested ?? 0);
                if ($qty <= 0) {
                    throw new RuntimeException('Transfer quantity must be positive.');
                }

                $this->ledgerPostingService->post([
                    'property_id' => $transfer->property_id,
                    'inventory_item_id' => $itemId,
                    'inventory_location_id' => $fromLocId,
                    'inventory_unit_id' => $unitId,
                    'movement_type' => InventoryMovementTypeEnum::TransferOut,
                    'direction' => InventoryMovementDirectionEnum::Out,
                    'source_leg' => InventoryMovementSourceLegEnum::Outbound,
                    'quantity' => $qty,
                    'source_domain' => 'inventory',
                    'source_type' => InventoryTransferLine::class,
                    'source_id' => $line->id,
                    'correlation_id' => $correlationId,
                    'idempotency_key' => "trf_post_{$transfer->id}_{$line->id}_out",
                    'occurred_at' => now(),
                    'created_by' => $actorId,
                ]);

                $this->ledgerPostingService->post([
                    'property_id' => $transfer->property_id,
                    'inventory_item_id' => $itemId,
                    'inventory_location_id' => $toLocId,
                    'inventory_unit_id' => $unitId,
                    'movement_type' => InventoryMovementTypeEnum::TransferIn,
                    'direction' => InventoryMovementDirectionEnum::In,
                    'source_leg' => InventoryMovementSourceLegEnum::Inbound,
                    'quantity' => $qty,
                    'source_domain' => 'inventory',
                    'source_type' => InventoryTransferLine::class,
                    'source_id' => $line->id,
                    'correlation_id' => $correlationId,
                    'idempotency_key' => "trf_post_{$transfer->id}_{$line->id}_in",
                    'occurred_at' => now(),
                    'created_by' => $actorId,
                ]);
            }

            return $transfer;
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
