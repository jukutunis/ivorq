<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementSourceLegEnum;
use RuntimeException;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class InventoryStockMovement extends Model
{
    use HasUlid;
    use BelongsToProperty;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'inventory_stock_movements';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'movement_type' => InventoryMovementTypeEnum::class,
            'direction' => InventoryMovementDirectionEnum::class,
            'source_leg' => InventoryMovementSourceLegEnum::class,
            'quantity' => 'decimal:3',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (InventoryStockMovement $movement) {
            throw new RuntimeException('Inventory Stock Movement is immutable and cannot be updated.');
        });

        static::deleting(function (InventoryStockMovement $movement) {
            throw new RuntimeException('Inventory Stock Movement is immutable and cannot be deleted.');
        });
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function location()
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function unit()
    {
        return $this->belongsTo(InventoryUnit::class, 'inventory_unit_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
