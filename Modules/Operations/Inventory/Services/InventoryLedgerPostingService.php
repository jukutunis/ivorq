<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementSourceLegEnum;
use Modules\Operations\Inventory\Models\InventoryStockMovement;
use RuntimeException;

class InventoryLedgerPostingService
{
    public function post(array $intent): InventoryStockMovement
    {
        $this->validateIntent($intent);

        $idempotencyKey = $intent['idempotency_key'];
        $propertyId = $intent['property_id'];

        $existing = InventoryStockMovement::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            $this->verifyIdempotentEquivalence($existing, $intent);
            return $existing;
        }

        return DB::transaction(function () use ($intent, $propertyId) {
            $sourceLeg = isset($intent['source_leg']) ? ((string) ($intent['source_leg'] instanceof InventoryMovementSourceLegEnum ? $intent['source_leg']->value : $intent['source_leg'])) : 'PRIMARY';

            $existingBySource = InventoryStockMovement::withoutGlobalScope('property')
                ->where('property_id', $propertyId)
                ->where('source_type', $intent['source_type'])
                ->where('source_id', $intent['source_id'])
                ->where('source_leg', $sourceLeg)
                ->first();

            if ($existingBySource) {
                throw new RuntimeException(
                    'A stock movement already exists for this source evidence.'
                );
            }

            $direction = $intent['direction'] instanceof InventoryMovementDirectionEnum
                ? $intent['direction'] : InventoryMovementDirectionEnum::tryFrom((string) $intent['direction']);

            if ($direction === InventoryMovementDirectionEnum::Out) {
                $this->lockControlledLedgerScope(
                    $propertyId,
                    $intent['inventory_item_id'],
                    $intent['inventory_location_id']
                );

                $currentQty = $this->computeControlledLedgerQuantity($propertyId, $intent['inventory_item_id'], $intent['inventory_location_id']);
                $requested = (float) $intent['quantity'];
                if ($requested > $currentQty) {
                    throw new RuntimeException(
                        "Insufficient controlled quantity: available {$currentQty}, requested {$requested}"
                    );
                }
            }

            return $this->createMovement($intent);
        });
    }

    private function validateIntent(array $intent): void
    {
        $required = [
            'property_id', 'inventory_item_id', 'inventory_location_id',
            'inventory_unit_id', 'movement_type', 'direction',
            'quantity',             'source_domain', 'source_type', 'source_id',
            'correlation_id', 'idempotency_key',
            'occurred_at', 'created_by',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $intent) || ($intent[$field] === null || $intent[$field] === '')) {
                throw new RuntimeException("Missing required posting field: {$field}");
            }
        }

        $movementType = $intent['movement_type'];
        if ($movementType instanceof InventoryMovementTypeEnum) {
            $movementType = $movementType->value;
        }
        if (!in_array($movementType, array_column(InventoryMovementTypeEnum::cases(), 'value'), true)) {
            throw new RuntimeException("Invalid movement type: {$movementType}");
        }

        $directionValue = $intent['direction'] instanceof InventoryMovementDirectionEnum
            ? $intent['direction']->value
            : $intent['direction'];

        if (!in_array($directionValue, array_column(InventoryMovementDirectionEnum::cases(), 'value'), true)) {
            throw new RuntimeException("Invalid direction: {$directionValue}.");
        }

        if (!isset($intent['quantity']) || (float) $intent['quantity'] <= 0) {
            throw new RuntimeException('Quantity must be positive.');
        }
    }

    private function verifyIdempotentEquivalence(InventoryStockMovement $existing, array $intent): void
    {
        if (bccomp((string) $existing->quantity, (string) $intent['quantity'], 3) !== 0) {
            throw new RuntimeException('Idempotent replay mismatch on quantity.');
        }

        $checks = [
            'property_id',
            'inventory_item_id',
            'inventory_location_id',
            'inventory_unit_id',
            'movement_type',
            'direction',
            'source_type',
            'source_id',
            'source_domain',
        ];

        foreach ($checks as $field) {
            $expected = $intent[$field];
            if ($expected instanceof \BackedEnum) {
                $expected = $expected->value;
            }
            $actual = $existing->{$field};
            if ($actual instanceof \BackedEnum) {
                $actual = $actual->value;
            }
            if ((string) $expected !== (string) $actual) {
                throw new RuntimeException(
                    "Idempotent replay mismatch on field '{$field}': "
                    . "expected '{$expected}', got '{$actual}'"
                );
            }
        }

        if (bccomp((string) $existing->quantity, (string) $intent['quantity'], 3) !== 0) {
            throw new RuntimeException('Idempotent replay mismatch on quantity.');
        }
    }

    private function lockControlledLedgerScope(string $propertyId, string $itemId, string $locationId): void
    {
        InventoryStockMovement::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->where('inventory_item_id', $itemId)
            ->where('inventory_location_id', $locationId)
            ->lockForUpdate()
            ->get(['id']);
    }

    private function computeControlledLedgerQuantity(string $propertyId, string $itemId, string $locationId): float
    {
        $result = InventoryStockMovement::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->where('inventory_item_id', $itemId)
            ->where('inventory_location_id', $locationId)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'IN' THEN quantity ELSE -quantity END), 0) as net")
            ->value('net');

        return (float) ($result ?? 0);
    }

    private function createMovement(array $intent): InventoryStockMovement
    {
        $now = Carbon::now();

        $movement = new InventoryStockMovement();

        $attributes = [
            'id' => (string) Str::ulid(),
            'property_id' => $intent['property_id'],
            'inventory_item_id' => $intent['inventory_item_id'],
            'inventory_location_id' => $intent['inventory_location_id'],
            'inventory_unit_id' => $intent['inventory_unit_id'],
            'movement_type' => $intent['movement_type'],
            'direction' => $intent['direction'],
            'quantity' => $intent['quantity'],
            'source_domain' => $intent['source_domain'],
            'source_type' => $intent['source_type'],
            'source_id' => $intent['source_id'],
            'source_leg' => isset($intent['source_leg']) ? $intent['source_leg'] : InventoryMovementSourceLegEnum::Primary,
            'correlation_id' => $intent['correlation_id'],
            'idempotency_key' => $intent['idempotency_key'],
            'occurred_at' => $intent['occurred_at'] ?? $now,
            'created_by' => $intent['created_by'],
            'created_at' => $now,
        ];

        foreach ($attributes as $key => $value) {
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }
            $movement->setAttribute($key, $value);
        }

        $movement->save();

        return $movement->fresh();
    }
}
