<?php

namespace Modules\Operations\Engineering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Enums\TechnicianAssignmentStatusEnum;
use Modules\Operations\Engineering\Enums\TechnicianRoleEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class TechnicianAssignment extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty;

    protected $fillable = [
        'property_id',
        'work_order_id',
        'user_id',
        'department_id',
        'role',
        'status',
        'assigned_by',
        'assigned_at',
        'started_at',
        'completed_at',
        'hours_worked',
        'remarks',
    ];

    protected $casts = [
        'role'         => TechnicianRoleEnum::class,
        'status'       => TechnicianAssignmentStatusEnum::class,
        'assigned_at'  => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'hours_worked' => 'decimal:2',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
