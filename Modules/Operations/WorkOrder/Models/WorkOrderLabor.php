<?php

namespace Modules\Operations\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Operations\WorkOrder\Enums\WorkOrderLaborStatusEnum;

class WorkOrderLabor extends Model
{
    use HasUlids, HasFactory, SoftDeletes;

    protected $table = 'work_order_labors';

    protected $fillable = [
        'work_order_id',
        'user_id',
        'status',
        'started_at',
        'ended_at',
        'actual_hours',
        'planned_hours',
        'hourly_rate',
        'total_cost',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => WorkOrderLaborStatusEnum::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'actual_hours' => 'decimal:2',
        'planned_hours' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
