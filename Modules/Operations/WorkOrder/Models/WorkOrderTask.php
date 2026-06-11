<?php

namespace Modules\Operations\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrderTask extends Model
{
    use HasUlids, HasFactory, SoftDeletes;

    protected $table = 'work_order_tasks';

    protected $fillable = [
        'work_order_id',
        'title',
        'description',
        'is_completed',
        'completed_at',
        'completed_by',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
