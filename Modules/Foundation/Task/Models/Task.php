<?php

namespace Modules\Foundation\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Task\Enums\TaskStatusEnum;
use Modules\Foundation\User\Models\User;
use Shared\Enums\PriorityEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Task extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes, LogsActivity;

    protected $fillable = [
        'property_id',
        'task_type',
        'source_module',
        'parent_task_id',
        'taskable_type',
        'taskable_id',
        'title',
        'description',
        'priority',
        'status',
        'due_date',
        'resolution_note',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'priority' => PriorityEnum::class,
        'status'   => TaskStatusEnum::class,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function subTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class);
    }

    public function watchers(): HasMany
    {
        return $this->hasMany(TaskWatcher::class);
    }
}
