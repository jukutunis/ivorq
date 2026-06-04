<?php

namespace Modules\Operations\Engineering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Engineering\Enums\PmFrequencyEnum;
use Modules\Operations\Engineering\Enums\PmStatusEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Zoning\Models\Zone;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class PreventiveMaintenance extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = [
        'property_id',
        'pm_code',
        'title',
        'description',
        'frequency',
        'frequency_days',
        'status',
        'room_id',
        'zone_id',
        'asset_description',
        'estimated_hours',
        'department_id',
        'last_run_at',
        'next_due_at',
    ];

    protected $casts = [
        'frequency'       => PmFrequencyEnum::class,
        'status'          => PmStatusEnum::class,
        'frequency_days'  => 'integer',
        'estimated_hours' => 'decimal:2',
        'last_run_at'     => 'datetime',
        'next_due_at'     => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(PreventiveMaintenanceTask::class);
    }
}
