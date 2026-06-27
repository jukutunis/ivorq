<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class InventoryValuationSequence extends Model
{
    use HasUlid, BelongsToProperty;

    protected $guarded = [];

    protected $casts = [
        'last_sequence' => 'integer',
    ];
}
