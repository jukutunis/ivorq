<?php

namespace Modules\Foundation\Approval\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\BelongsToProperty;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ApprovalRequest extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
        'notes' => 'json',
        'workflow_snapshot' => 'json',
        'step_snapshot' => 'json',
        'matrix_snapshot' => 'json',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logUnguarded()
            ->logOnlyDirty();
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(ApprovalStep::class, 'current_step_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class, 'approval_request_id');
    }
}
