<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class InventoryUnit extends Model
{
    use BelongsToProperty, HasAuditColumns, HasUlid, SoftDeletes;

    protected $table = 'inventory_units';

    protected $fillable = [
        'property_id',
        'code',
        'name',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
