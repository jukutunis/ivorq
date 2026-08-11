<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class RoomInspection extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'room_inspections';

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'property_id',
        'room_id',
        'cleaning_task_id',
        'supervisor_id',
        'inspection_type',
        'status',
        'inspection_severity',
        'score',
        'max_score',
        'is_passed',
        'remarks',
        'inspected_at',
        'notes',
        'results',
        'claimed_at',
        'claim_idempotency_key',
        'claim_source_hash',
        'claim_evidence_version',
    ];

    protected $casts = [
        'is_passed' => 'boolean',
        'results' => 'array',
        'score' => 'integer',
        'max_score' => 'integer',
        'status' => \Modules\Operations\Housekeeping\Enums\InspectionStatusEnum::class,
        'inspection_type' => \Modules\Operations\Housekeeping\Enums\InspectionTypeEnum::class,
        'inspection_severity' => \Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum::class,
        'inspected_at' => 'datetime',
        'claimed_at' => 'datetime',
        'claim_evidence_version' => 'integer',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function task()
    {
        return $this->belongsTo(CleaningTask::class, 'cleaning_task_id');
    }

    public function inspector()
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'supervisor_id');
    }

    public function photos()
    {
        return $this->hasMany(InspectionPhoto::class);
    }

    public function reCleaningTask()
    {
        return $this->hasOne(CleaningTask::class, 'rework_source_inspection_id');
    }

    protected static function booted(): void
    {
        static::updating(function (RoomInspection $inspection): void {
            $originalStatus = (string) $inspection->getRawOriginal('status');
            if (in_array($originalStatus, ['passed', 'failed'], true)) {
                throw new \DomainException('Terminal Room Inspection evidence is immutable.');
            }

            $originalType = (string) $inspection->getRawOriginal('inspection_type');
            if (
                $originalType === 'post_cleaning'
                && collect(['property_id', 'room_id', 'cleaning_task_id', 'inspection_type'])
                    ->contains(fn (string $field) => $inspection->isDirty($field))
            ) {
                throw new \DomainException('Post-cleaning Room Inspection source evidence is immutable.');
            }

            if (
                (int) $inspection->getRawOriginal('claim_evidence_version') === 1
                && collect([
                    'property_id', 'room_id', 'cleaning_task_id', 'inspection_type',
                    'supervisor_id', 'claimed_at', 'claim_idempotency_key',
                    'claim_source_hash', 'claim_evidence_version', 'deleted_at',
                ])->contains(fn (string $field) => $inspection->isDirty($field))
            ) {
                throw new \DomainException('Committed Room Inspection claim evidence is immutable.');
            }
        });

        static::deleting(function (RoomInspection $inspection): void {
            $status = $inspection->status instanceof \BackedEnum ? $inspection->status->value : (string) $inspection->status;
            $type = $inspection->inspection_type instanceof \BackedEnum
                ? $inspection->inspection_type->value
                : (string) $inspection->inspection_type;
            if (in_array($status, ['passed', 'failed'], true) || $type === 'post_cleaning') {
                throw new \DomainException('Committed Room Inspection evidence cannot be deleted.');
            }
        });
    }
}
