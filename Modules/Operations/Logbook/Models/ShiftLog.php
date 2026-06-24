<?php

namespace Modules\Operations\Logbook\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class ShiftLog extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'shift_logs';

    protected $fillable = [
        'property_id',
        'shift_id',
        'department_id',
        'area',
        'subject',
        'content',
        'category',
        'priority',
        'status',
        'requires_follow_up',
        'created_by',
        'submitted_by',
        'submitted_at',
        'acknowledged_by',
        'acknowledged_at',
    ];

    protected $casts = [
        'requires_follow_up' => 'boolean',
        'submitted_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'status' => \Modules\Operations\Logbook\Enums\ShiftLogStatusEnum::class,
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(\Modules\Foundation\Property\Models\Property::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'submitted_by');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'acknowledged_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(\Modules\Foundation\Department\Models\Shift::class, 'shift_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(\Modules\Foundation\Department\Models\Department::class, 'department_id');
    }
}
