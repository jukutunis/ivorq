<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class TaskAssignment extends Model
{
    use HasUlids, \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'housekeeping_task_assignments';

    protected $fillable = [
        'cleaning_task_id',
        'property_id',
        'attendant_id',
        'user_id',
        'department_id',
        'status',
        'assigned_at',
        'accepted_at',
        'completed_at',
        'assigned_by',
        'assignment_action',
        'idempotency_key',
        'source_hash',
        'evidence_version',
        'previous_assignment_id',
        'closed_at',
        'closed_by',
        'closure_reason',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'accepted_at' => 'datetime',
        'completed_at' => 'datetime', // From legacy DB fix
        'closed_at' => 'datetime',
        'status' => \Modules\Operations\Housekeeping\Enums\AssignmentStatusEnum::class,
    ];

    public function user()
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class);
    }

    public function department()
    {
        return $this->belongsTo(\Modules\Foundation\Department\Models\Department::class);
    }

    public function task()
    {
        return $this->belongsTo(CleaningTask::class, 'cleaning_task_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'assigned_by');
    }

    public function previousAssignment()
    {
        return $this->belongsTo(self::class, 'previous_assignment_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'closed_by');
    }

    protected static function booted(): void
    {
        static::updating(function (TaskAssignment $assignment): void {
            $originalStatus = (string) $assignment->getRawOriginal('status');
            if ($originalStatus !== 'active') {
                throw new \DomainException('Terminal Housekeeping assignment evidence is immutable.');
            }

            $immutable = [
                'property_id', 'cleaning_task_id', 'user_id', 'attendant_id', 'department_id',
                'assigned_by', 'assigned_at', 'assignment_action', 'idempotency_key', 'source_hash',
                'evidence_version', 'previous_assignment_id', 'accepted_at', 'deleted_at',
            ];
            if (collect($immutable)->contains(fn (string $field) => $assignment->isDirty($field))) {
                throw new \DomainException('Housekeeping assignment source evidence is immutable.');
            }

            $newStatus = $assignment->status instanceof \BackedEnum
                ? $assignment->status->value
                : (string) $assignment->status;
            if (! in_array($newStatus, ['completed', 'cancelled'], true)) {
                throw new \DomainException('Housekeeping assignment closure is invalid.');
            }
        });

        static::deleting(function (): never {
            throw new \DomainException('Housekeeping assignment evidence cannot be deleted.');
        });
    }
}
