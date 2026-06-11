<?php

namespace Modules\Operations\ContractorPTW\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractorWorkerPropertyProfile extends Model
{
    use HasUlid;
    use BelongsToProperty;
    use SoftDeletes;

    protected $table = 'contractor_worker_property_profiles';
    protected $guarded = [];
}
