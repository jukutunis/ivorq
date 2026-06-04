<?php

namespace Modules\Operations\Zoning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Zoning\Enums\ZoneAssignmentStatusEnum;
use Modules\Operations\Zoning\Enums\ZonePriorityEnum;
use Modules\Operations\Zoning\Enums\ZoneStatusEnum;
use Modules\Operations\Zoning\Enums\ZoneTypeEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class Zone extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = [
        'property_id',
        'zone_code',
        'zone_name',
        'zone_type',
        'description',
        'status',
        'priority',
    ];

    protected $casts = [
        'status'    => ZoneStatusEnum::class,
        'zone_type' => ZoneTypeEnum::class,
        'priority'  => ZonePriorityEnum::class,
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ZoneAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->hasMany(ZoneAssignment::class)
                    ->where('status', ZoneAssignmentStatusEnum::Active->value);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ZoneHistory::class);
    }
}
