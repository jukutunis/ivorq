<?php

namespace Modules\Operations\NightAudit\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Shared\Exceptions\BusinessLogicException;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class NightAuditRun extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, HasFactory, LogsActivity;

    protected $table = 'night_audit_runs';

    protected $fillable = [
        'property_id',
        'property_business_date_id',
        'business_date_snapshot',
        'property_timezone_snapshot',
        'attempt_number',
        'status',
        'started_by',
        'started_at',
        'aborted_by',
        'aborted_at',
        'abort_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'business_date_snapshot' => 'date',
        'attempt_number' => 'integer',
        'status' => NightAuditRunStatusEnum::class,
        'started_at' => 'datetime',
        'aborted_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function propertyBusinessDate(): BelongsTo
    {
        return $this->belongsTo(PropertyBusinessDate::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function abortingActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aborted_by');
    }

    public function delete()
    {
        throw new BusinessLogicException('Night Audit runs are immutable evidence and cannot be deleted.');
    }

    public function forceDelete()
    {
        throw new BusinessLogicException('Night Audit runs are immutable evidence and cannot be deleted.');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('night_audit_run')
            ->logOnly([
                'property_id',
                'property_business_date_id',
                'business_date_snapshot',
                'property_timezone_snapshot',
                'attempt_number',
                'status',
                'started_by',
                'started_at',
                'aborted_by',
                'aborted_at',
                'abort_reason',
            ])
            ->logOnlyDirty();
    }

    protected static function newFactory()
    {
        return \Database\Factories\NightAuditRunFactory::new();
    }
}
