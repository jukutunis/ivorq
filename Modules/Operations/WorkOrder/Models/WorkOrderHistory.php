<?php

namespace Modules\Operations\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrderHistory extends Model
{
    use HasUlids, HasFactory;

    protected $table = 'work_order_histories';

    protected $fillable = [
        'work_order_id',
        'user_id',
        'action',
        'field',
        'old_value',
        'new_value',
        'description',
        'created_by',
        'updated_by',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
