<?php

namespace Modules\Operations\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrderAssignment extends Model
{
    use HasUlids, HasFactory, SoftDeletes;

    protected $table = 'work_order_assignments';

    protected $fillable = [
        'work_order_id',
        'user_id',
        'department_id',
        'status',
        'created_by',
        'updated_by',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
