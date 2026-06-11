<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class InventoryTransaction extends Model
{
    use HasUlid, BelongsToProperty;

    protected $guarded = [];
    public const UPDATED_AT = null;
}
