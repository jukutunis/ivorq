<?php

namespace Modules\Foundation\Authorization\Models;

use Shared\Traits\HasUlid;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    use HasUlid;
}
