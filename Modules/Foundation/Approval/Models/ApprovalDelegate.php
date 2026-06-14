<?php

namespace Modules\Foundation\Approval\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\BelongsToProperty;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ApprovalDelegate extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logUnguarded()
            ->logOnlyDirty();
    }
}
