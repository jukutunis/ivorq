<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Enums\LocationTypeEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class InventoryLocation extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $table = 'inventory_locations';

    protected $fillable = [
        'property_id',
        'location_code',
        'name',
        'description',
        'location_type',
        'is_active',
    ];

    protected $casts = [
        'location_type' => LocationTypeEnum::class,
        'is_active'     => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(InventoryStockBalance::class, 'location_id');
    }

    public function stockCards(): HasMany
    {
        return $this->hasMany(InventoryStockCard::class, 'location_id');
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(InventoryReceiptLine::class, 'location_id');
    }

    public function issueLines(): HasMany
    {
        return $this->hasMany(InventoryIssueLine::class, 'location_id');
    }

    public function transfersFrom(): HasMany
    {
        return $this->hasMany(InventoryTransfer::class, 'from_location_id');
    }

    public function transfersTo(): HasMany
    {
        return $this->hasMany(InventoryTransfer::class, 'to_location_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class, 'location_id');
    }
}
