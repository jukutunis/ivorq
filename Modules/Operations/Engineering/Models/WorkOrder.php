<?php

namespace Modules\Operations\Engineering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Enums\WorkOrderPriorityEnum;
use Modules\Operations\Engineering\Enums\WorkOrderStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderTypeEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Zoning\Models\Zone;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class WorkOrder extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = [
        'property_id',
        'work_order_number',
        'title',
        'description',
        'work_order_type',
        'priority',
        'status',
        'location_type',
        'room_id',
        'zone_id',
        'location_description',
        'asset_description',
        'sla_hours',
        'estimated_hours',
        'actual_hours',
        'due_date',
        'started_at',
        'completed_at',
        'completed_by',
        'on_hold_reason',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'work_order_type' => WorkOrderTypeEnum::class,
        'status'          => WorkOrderStatusEnum::class,
        'priority'        => WorkOrderPriorityEnum::class,
        'sla_hours'       => 'decimal:2',
        'estimated_hours' => 'decimal:2',
        'actual_hours'    => 'decimal:2',
        'due_date'        => 'datetime',
        'started_at'      => 'datetime',
        'completed_at'    => 'datetime',
        'cancelled_at'    => 'datetime',
        'approved_at'     => 'datetime',
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

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TechnicianAssignment::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(WorkOrderStatusHistory::class);
    }

    public function assetRequests(): HasMany
    {
        return $this->hasMany(AssetRequest::class);
    }
}
