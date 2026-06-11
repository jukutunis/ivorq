<?php

namespace Modules\Operations\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Operations\WorkOrder\Enums\WorkOrderApprovalModeEnum;

class WorkOrderApproval extends Model
{
    use HasUlids, HasFactory, SoftDeletes;

    protected $table = 'work_order_approvals';

    protected $fillable = [
        'work_order_id',
        'approver_id',
        'status',
        'mode',
        'step',
        'comments',
        'approved_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'mode' => WorkOrderApprovalModeEnum::class,
        'step' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
