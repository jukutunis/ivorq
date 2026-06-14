<?php

namespace Modules\Foundation\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class TaskAssignment extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, LogsActivity;

    protected $fillable = [
        'task_id',
        'property_id',
        'assignee_type',
        'assignee_id',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function assignee(): MorphTo
    {
        return $this->morphTo();
    }
}
