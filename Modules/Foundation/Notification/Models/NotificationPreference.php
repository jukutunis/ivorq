<?php

namespace Modules\Foundation\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Department\Models\Department;

class NotificationPreference extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, LogsActivity;

    protected $fillable = [
        'property_id',
        'user_id',
        'department_id',
        'notification_type',
        'in_app_enabled',
        'email_enabled',
        'push_enabled',
        'is_muted',
    ];

    protected $casts = [
        'in_app_enabled' => 'boolean',
        'email_enabled' => 'boolean',
        'push_enabled' => 'boolean',
        'is_muted' => 'boolean',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
