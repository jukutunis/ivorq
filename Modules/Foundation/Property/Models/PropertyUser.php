<?php

namespace Modules\Foundation\Property\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class PropertyUser extends Pivot
{
    protected $table = 'property_user';

    public $incrementing = false;

    protected $casts = [
        'is_default' => 'boolean',
        'joined_at'  => 'datetime',
    ];
}
