<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class InventoryItem extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $table = 'inventory_items';

    protected $fillable = [
        'property_id',
        'item_code',
        'name',
        'description',
        'category_id',
        'unit_id',
        'sku',
        'barcode',
        'min_stock',
        'max_stock',
        'reorder_point',
        'reorder_quantity',
        'average_cost',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'min_stock'        => 'decimal:3',
        'max_stock'        => 'decimal:3',
        'reorder_point'    => 'decimal:3',
        'reorder_quantity' => 'decimal:3',
        'average_cost'     => 'decimal:4',
        'is_active'        => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'unit_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(InventoryStockBalance::class, 'item_id');
    }

    public function stockCards(): HasMany
    {
        return $this->hasMany(InventoryStockCard::class, 'item_id');
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(InventoryReceiptLine::class, 'item_id');
    }

    public function issueLines(): HasMany
    {
        return $this->hasMany(InventoryIssueLine::class, 'item_id');
    }

    public function transferLines(): HasMany
    {
        return $this->hasMany(InventoryTransferLine::class, 'item_id');
    }

    public function adjustmentLines(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentLine::class, 'item_id');
    }
}
