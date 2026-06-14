<?php

namespace Modules\Foundation\Approval\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\BelongsToProperty;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ApprovalMatrixRule extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logUnguarded()
            ->logOnlyDirty();
    }
}
