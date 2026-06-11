<?php

namespace Modules\Operations\Maintenance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Foundation\User\Models\User;

class MaintenanceHistory extends Model
{
    use HasUlids;

    protected $table = 'maintenance_histories';
    protected $guarded = ['id'];
    protected $casts = [
        'executed_date' => 'date',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(MaintenanceExecution::class, 'maintenance_execution_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
