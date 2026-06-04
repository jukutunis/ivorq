<?php

namespace Modules\Operations\Engineering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Enums\AssetRequestStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderPriorityEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class AssetRequest extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = [
        'property_id',
        'request_number',
        'work_order_id',
        'requester_id',
        'department_id',
        'title',
        'description',
        'status',
        'priority',
        'required_by',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'fulfilled_at',
        'fulfilled_by',
    ];

    protected $casts = [
        'status'       => AssetRequestStatusEnum::class,
        'priority'     => WorkOrderPriorityEnum::class,
        'required_by'  => 'datetime',
        'approved_at'  => 'datetime',
        'rejected_at'  => 'datetime',
        'fulfilled_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }
}
