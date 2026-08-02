<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class CleaningTask extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'cleaning_tasks';

    protected $attributes = [
        'status' => 'pending',
        'priority' => 'normal',
    ];

    protected $dispatchesEvents = [
        'created' => \Modules\Operations\Housekeeping\Events\CleaningTaskCreated::class,
    ];

    protected $fillable = [
        'property_id',
        'room_id',
        'zone_id',
        'task_code',
        'title',
        'task_type',
        'status',
        'priority',
        'credits',
        'scheduled_at',
        'due_date',
        'started_at',
        'completed_at',
        'completed_by',
        'verified_at',
        'sla_minutes_target',
        'sla_breached',
        'notes',
        'created_by',
        'rework_source_inspection_id',
        'source_cleaning_task_id',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'due_date' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'sla_breached' => 'boolean',
        'credits' => 'decimal:2',
        'status' => \Modules\Operations\Housekeeping\Enums\TaskStatusEnum::class,
        'task_type' => \Modules\Operations\Housekeeping\Enums\TaskTypeEnum::class,
    ];

    public function zone()
    {
        return $this->belongsTo(\Modules\Operations\Zoning\Models\Zone::class);
    }

    public function property()
    {
        return $this->belongsTo(\Modules\Foundation\Property\Models\Property::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function completedBy()
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'completed_by');
    }

    public function assignments()
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function inspections()
    {
        return $this->hasMany(RoomInspection::class);
    }

    public function reworkSourceInspection()
    {
        return $this->belongsTo(RoomInspection::class, 'rework_source_inspection_id');
    }

    public function sourceCleaningTask()
    {
        return $this->belongsTo(self::class, 'source_cleaning_task_id');
    }

    protected static function booted(): void
    {
        static::updating(function (CleaningTask $task): void {
            $originalStatus = (string) $task->getRawOriginal('status');
            $completedEvidence = $originalStatus === 'completed' || $task->getRawOriginal('completed_at') !== null;
            $protected = [
                'property_id', 'room_id', 'status', 'completed_at', 'completed_by',
                'notes', 'rework_source_inspection_id', 'source_cleaning_task_id', 'deleted_at',
            ];

            if ($completedEvidence && collect($protected)->contains(fn (string $field) => $task->isDirty($field))) {
                throw new \DomainException('Completed Cleaning Task lifecycle evidence is immutable.');
            }

            if ($task->isDirty('verified_at')) {
                if (
                    $originalStatus !== 'completed'
                    || $task->getRawOriginal('verified_at') !== null
                    || $task->verified_at === null
                    || ! RoomInspection::withoutGlobalScopes()
                        ->where('cleaning_task_id', $task->id)
                        ->where('property_id', $task->property_id)
                        ->where('room_id', $task->room_id)
                        ->where('status', 'passed')
                        ->exists()
                ) {
                    throw new \DomainException('Cleaning Task verification may only be recorded by a committed inspection pass.');
                }
            }

            if (
                $task->getRawOriginal('rework_source_inspection_id') !== null
                && collect(['property_id', 'room_id', 'rework_source_inspection_id', 'source_cleaning_task_id'])
                    ->contains(fn (string $field) => $task->isDirty($field))
            ) {
                throw new \DomainException('Re-cleaning source evidence is immutable.');
            }
        });

        static::deleting(function (CleaningTask $task): void {
            $status = $task->status instanceof \BackedEnum ? $task->status->value : (string) $task->status;
            if ($status === 'completed' || $task->rework_source_inspection_id !== null) {
                throw new \DomainException('Committed Cleaning Task lifecycle evidence cannot be deleted.');
            }
        });
    }
}
