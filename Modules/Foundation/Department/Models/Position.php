<?php

namespace Modules\Foundation\Department\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class Position extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty;

    protected $fillable = [
        'property_id',
        'department_id',
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

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
