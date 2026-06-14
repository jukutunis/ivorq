<?php

namespace Modules\Foundation\Department\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class Position extends Model
{
    use HasUlid, HasAuditColumns;

    protected $fillable = [
        'name',
        'code',
        'level',
        'description',
        'is_active',
    ];

    protected $casts = [
        'level'     => 'integer',
        'is_active' => 'boolean',
    ];

    // Global model, no property or department relationship
}
