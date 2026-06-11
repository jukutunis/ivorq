<?php

namespace Modules\Operations\Maintenance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceChecklist extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'maintenance_checklists';
    protected $guarded = ['id'];
    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }
}
