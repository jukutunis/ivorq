<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class InventoryTransferLine extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'inventory_transfer_lines';

    protected $fillable = [
        'property_id',
        'transfer_id',
        'item_id',
        'quantity_requested',
        'notes',
    ];

    protected $casts = [
        'quantity_requested' => 'decimal:3',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'transfer_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
