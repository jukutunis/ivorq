<?php

namespace Modules\Operations\ContractorPTW\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class ContractorAccessPass extends Model
{
    use HasUlid;
    use BelongsToProperty;

    protected $table = 'contractor_access_passes';
    protected $guarded = [];
}
