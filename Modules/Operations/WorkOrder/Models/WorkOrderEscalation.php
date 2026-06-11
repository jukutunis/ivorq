<?php

namespace Modules\Operations\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrderEscalation extends Model
{
    use HasUlids, HasFactory, SoftDeletes;

    protected $table = 'work_order_escalations';

    protected $fillable = [
        'work_order_id',
        'escalated_to_user_id',
        'escalated_to_department_id',
        'reason',
        'notes',
        'is_resolved',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_resolved' => 'boolean',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
