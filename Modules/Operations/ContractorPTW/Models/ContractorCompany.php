<?php

namespace Modules\Operations\ContractorPTW\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractorCompany extends Model
{
    use HasUlid;
    use SoftDeletes;

    protected $table = 'contractor_companies';
    protected $guarded = [];
}
