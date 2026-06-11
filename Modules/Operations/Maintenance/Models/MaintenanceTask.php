<?php

namespace Modules\Operations\Maintenance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceTask extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'maintenance_tasks';
    protected $guarded = ['id'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }
}
