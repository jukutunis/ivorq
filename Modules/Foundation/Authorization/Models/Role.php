<?php

namespace Modules\Foundation\Authorization\Models;

use Shared\Traits\HasUlid;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Role extends SpatieRole
{
    use HasUlid, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
