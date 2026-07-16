<?php

namespace Modules\Foundation\Property\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;

class PropertyBusinessDate extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, HasFactory, LogsActivity;

    protected $table = 'property_business_dates';

    protected $fillable = [
        'property_id',
        'business_date',
        'timezone_snapshot',
        'status',
        'is_open',
        'opened_by',
        'closed_by',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'timezone_snapshot' => 'string',
        'status' => PropertyBusinessDateStatusEnum::class,
        'is_open' => 'boolean',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function delete()
    {
        throw new \Shared\Exceptions\BusinessLogicException("Business Dates cannot be deleted. They must be transitioned through the standard lifecycle.");
    }

    public function forceDelete()
    {
        throw new \Shared\Exceptions\BusinessLogicException("Business Dates cannot be deleted. They must be transitioned through the standard lifecycle.");
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    protected static function newFactory()
    {
        return \Database\Factories\PropertyBusinessDateFactory::new();
    }
}
