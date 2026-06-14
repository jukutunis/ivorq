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
        'line_total',
        'expiry_date',
        'invoiced_quantity',
        'invoiced_amount',
    ];

    protected $casts = [
        'quantity'    => 'decimal:3',
        'unit_cost'   => 'decimal:3',
        'line_total'  => 'decimal:3',
        'expiry_date' => 'date',
        'invoiced_quantity' => 'decimal:3',
        'invoiced_amount' => 'decimal:3',
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
