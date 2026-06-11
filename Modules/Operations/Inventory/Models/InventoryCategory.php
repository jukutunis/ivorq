<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class InventoryCategory extends Model
{
    use HasUlid, BelongsToProperty, SoftDeletes;

    protected $guarded = [];
}
