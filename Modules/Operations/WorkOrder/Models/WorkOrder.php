<?php

namespace Modules\Operations\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Operations\WorkOrder\Enums\WorkOrderStatusEnum;
use Modules\Operations\WorkOrder\Enums\WorkOrderPriorityEnum;
use Modules\Operations\WorkOrder\Enums\WorkOrderTypeEnum;
use Modules\Operations\WorkOrder\Enums\WorkOrderSourceTypeEnum;

class WorkOrder extends Model
{
    use HasUlids, HasFactory, SoftDeletes;

    protected $table = 'work_orders';

    protected $fillable = [
        'property_id',
        'wo_number',
        'asset_id',
        'title',
        'description',
        'status',
        'priority',
        'type',
        'source_type',
        'source_id',
        'has_guest_impact',
        'priority_score',
        'target_resolution_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => WorkOrderStatusEnum::class,
        'priority' => WorkOrderPriorityEnum::class,
        'type' => WorkOrderTypeEnum::class,
        'source_type' => WorkOrderSourceTypeEnum::class,
        'has_guest_impact' => 'boolean',
        'priority_score' => 'integer',
        'target_resolution_at' => 'datetime',
    ];

    public function tasks()
    {
        return $this->hasMany(WorkOrderTask::class);
    }

    public function assignments()
    {
        return $this->hasMany(WorkOrderAssignment::class);
    }

    public function labors()
    {
        return $this->hasMany(WorkOrderLabor::class);
    }

    public function materials()
    {
        return $this->hasMany(WorkOrderMaterial::class);
    }

    public function approvals()
    {
        return $this->hasMany(WorkOrderApproval::class);
    }

    public function slas()
    {
        return $this->hasMany(WorkOrderSLA::class);
    }

    public function escalations()
    {
        return $this->hasMany(WorkOrderEscalation::class);
    }

    public function comments()
    {
        return $this->hasMany(WorkOrderComment::class);
    }

    public function watchers()
    {
        return $this->hasMany(WorkOrderWatcher::class);
    }

    public function closures()
    {
        return $this->hasMany(WorkOrderClosure::class);
    }

    public function histories()
    {
        return $this->hasMany(WorkOrderHistory::class);
    }
}
