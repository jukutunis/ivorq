<?php

namespace Modules\Operations\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrderMaterial extends Model
{
    use HasUlids, HasFactory, SoftDeletes;

    protected $table = 'work_order_materials';

    protected $fillable = [
        'work_order_id',
        'material_type',
        'material_id',
        'item_name',
        'quantity',
        'unit_cost',
        'total_cost',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
