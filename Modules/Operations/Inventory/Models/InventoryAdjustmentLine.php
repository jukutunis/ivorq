<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class InventoryAdjustmentLine extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'inventory_adjustment_lines';

    protected $fillable = [
        'property_id',
        'adjustment_id',
        'item_id',
        'quantity_system',
        'quantity_actual',
        'quantity_variance',
        'unit_cost',
        'notes',
    ];

    protected $casts = [
        'quantity_system'   => 'decimal:3',
        'quantity_actual'   => 'decimal:3',
        'quantity_variance' => 'decimal:3',
        'unit_cost'         => 'decimal:4',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustment::class, 'adjustment_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
