<?php

namespace Modules\Operations\Maintenance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Foundation\User\Models\User;

class MaintenanceExecution extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'maintenance_executions';
    protected $guarded = ['id'];
    protected $casts = [
        'scheduled_date' => 'date',
        'executed_date' => 'date',
        'checklist_snapshot' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
