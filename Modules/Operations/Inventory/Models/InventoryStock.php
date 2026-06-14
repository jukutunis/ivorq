<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class InventoryStock extends Model
{
    use HasUlid, BelongsToProperty;

    protected $guarded = [];

    protected $casts = [
        'physical_quantity' => 'decimal:4',
        'reserved_quantity' => 'decimal:4',
        'status'            => \Modules\Operations\Inventory\Enums\ItemStatusEnum::class,
        'last_movement_at'  => 'datetime',
    ];

    public function property()
    {
        return $this->belongsTo(\Modules\Foundation\Property\Models\Property::class);
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function location()
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function getAvailableQuantityAttribute()
    {
        return max(0, $this->physical_quantity - $this->reserved_quantity);
    }
}
