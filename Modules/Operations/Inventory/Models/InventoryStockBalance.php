<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class InventoryStockBalance extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'inventory_stock_balances';

    protected $fillable = [
        'property_id',
        'item_id',
        'location_id',
        'quantity',
        'status',
        'last_movement_at',
    ];

    protected $casts = [
        'quantity'         => 'decimal:3',
        'status'           => ItemStatusEnum::class,
        'last_movement_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }
}
