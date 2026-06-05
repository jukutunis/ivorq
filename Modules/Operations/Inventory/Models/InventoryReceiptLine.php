<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class InventoryReceiptLine extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'inventory_receipt_lines';

    protected $fillable = [
        'property_id',
        'receipt_id',
        'item_id',
        'location_id',
        'quantity',
        'unit_cost',
        'total_value',
        'notes',
    ];

    protected $casts = [
        'quantity'    => 'decimal:3',
        'unit_cost'   => 'decimal:4',
        'total_value' => 'decimal:4',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(InventoryReceipt::class, 'receipt_id');
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
