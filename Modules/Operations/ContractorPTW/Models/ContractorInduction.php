<?php

namespace Modules\Operations\ContractorPTW\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class ContractorInduction extends Model
{
    use HasUlid;
    use BelongsToProperty;

    protected $table = 'contractor_inductions';
    protected $guarded = [];
}
