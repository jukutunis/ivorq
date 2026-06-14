<?php

namespace Modules\Foundation\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class NotificationTemplate extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes, LogsActivity;

    protected $fillable = [
        'property_id',
        'notification_type',
        'channel',
        'locale',
        'subject',
        'body_template',
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
        return $this->belongsTo(\Modules\Foundation\Property\Models\Property::class, 'property_id');
    }
}
