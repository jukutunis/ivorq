<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class InventoryItem extends Model
{
    use BelongsToProperty, HasAuditColumns, HasUlid, SoftDeletes;

    protected $fillable = [
        'property_id',
        'sku',
        'name',
        'category_id',
        'inventory_type',
        'criticality',
        'is_batch_tracked',
        'is_expiry_tracked',
        'weighted_average_cost',
        'is_active',
        'reorder_point',
    ];

    protected $casts = [
        'is_batch_tracked' => 'boolean',
        'is_expiry_tracked' => 'boolean',
        'is_active' => 'boolean',
        'weighted_average_cost' => 'decimal:2',
        'reorder_point' => 'decimal:4',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'item_id');
    }
}
