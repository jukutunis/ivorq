<?php

namespace Modules\Operations\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrderSLA extends Model
{
    use HasUlids, HasFactory, SoftDeletes;

    protected $table = 'work_order_slas';

    protected $fillable = [
        'work_order_id',
        'target_response_at',
        'actual_response_at',
        'is_response_breached',
        'target_resolution_at',
        'actual_resolution_at',
        'is_resolution_breached',
        'total_pause_minutes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'target_response_at' => 'datetime',
        'actual_response_at' => 'datetime',
        'is_response_breached' => 'boolean',
        'target_resolution_at' => 'datetime',
        'actual_resolution_at' => 'datetime',
        'is_resolution_breached' => 'boolean',
        'total_pause_minutes' => 'integer',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
