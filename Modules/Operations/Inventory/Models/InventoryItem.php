<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class InventoryItem extends Model
{
    use HasUlid, BelongsToProperty, SoftDeletes;

    protected $guarded = [];

    public function getIsActiveAttribute(): bool
    {
        // Treat as active if not deleted
        return ! $this->trashed();
    }

    public function category()
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function unit()
    {
        return $this->belongsTo(InventoryUnit::class, 'unit_id');
    }

    public function stockBalances()
    {
        return $this->hasMany(InventoryStock::class, 'item_id');
    }
}
