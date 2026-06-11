<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class InventoryConversion extends Model
{
    use HasUlid, BelongsToProperty;

    protected $guarded = [];
}
