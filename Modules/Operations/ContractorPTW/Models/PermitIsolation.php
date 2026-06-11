<?php

namespace Modules\Operations\ContractorPTW\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class PermitIsolation extends Model
{
    use HasUlid;

    protected $table = 'permit_isolations';
    protected $guarded = [];
}
