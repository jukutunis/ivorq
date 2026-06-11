<?php

namespace Modules\Operations\Maintenance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Foundation\User\Models\User;

class MaintenancePlan extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'maintenance_plans';
    protected $guarded = ['id'];
    protected $casts = [
        'next_due_date' => 'date',
        'last_executed_date' => 'date',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class);
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(MaintenanceChecklist::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(MaintenanceExecution::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
