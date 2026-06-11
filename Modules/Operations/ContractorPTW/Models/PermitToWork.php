<?php

namespace Modules\Operations\ContractorPTW\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;
use Illuminate\Database\Eloquent\SoftDeletes;

class PermitToWork extends Model
{
    use HasUlid;
    use BelongsToProperty;
    use SoftDeletes;

    protected $table = 'permit_to_works';
    protected $guarded = [];
}
