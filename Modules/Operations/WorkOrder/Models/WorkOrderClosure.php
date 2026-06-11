<?php

namespace Modules\Operations\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrderClosure extends Model
{
    use HasUlids, HasFactory, SoftDeletes;

    protected $table = 'work_order_closures';

    protected $fillable = [
        'work_order_id',
        'closed_by_user_id',
        'closed_at',
        'resolution_notes',
        'root_cause',
        'has_signature',
        'snapshot_data',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'has_signature' => 'boolean',
        'snapshot_data' => 'array',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
