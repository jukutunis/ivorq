<?php

namespace Modules\Operations\Maintenance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Foundation\User\Models\User;

class MaintenanceException extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'maintenance_exceptions';
    protected $guarded = ['id'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(MaintenanceExecution::class, 'maintenance_execution_id');
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(MaintenanceChecklist::class, 'maintenance_checklist_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
