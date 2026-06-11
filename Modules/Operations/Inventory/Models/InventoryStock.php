<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class InventoryStock extends Model
{
    use HasUlid, BelongsToProperty;

    protected $guarded = [];
    
    public function getAvailableQuantityAttribute()
    {
        return $this->physical_quantity - $this->reserved_quantity;
    }
}
