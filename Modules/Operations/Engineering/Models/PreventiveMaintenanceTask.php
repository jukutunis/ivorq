<?php

namespace Modules\Operations\Engineering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Enums\PmTaskStatusEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class PreventiveMaintenanceTask extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty;

    protected $fillable = [
        'property_id',
        'preventive_maintenance_id',
        'work_order_id',
        'scheduled_date',
        'status',
        'completed_at',
        'completed_by',
        'remarks',
    ];

    protected $casts = [
        'status'         => PmTaskStatusEnum::class,
        'scheduled_date' => 'datetime',
        'completed_at'   => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function preventiveMaintenance(): BelongsTo
    {
        return $this->belongsTo(PreventiveMaintenance::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
