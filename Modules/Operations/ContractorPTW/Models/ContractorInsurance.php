<?php

namespace Modules\Operations\ContractorPTW\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractorInsurance extends Model
{
    use HasUlid;
    use SoftDeletes;

    protected $table = 'contractor_insurances';
    protected $guarded = [];
}
