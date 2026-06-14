<?php

namespace Modules\Foundation\Activity\Models;

use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

class Activity extends SpatieActivity
{
    use HasUlid;
}
