<?php

namespace Modules\Operations\ContractorPTW\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class ContractorCertification extends Model
{
    use HasUlid;

    protected $table = 'contractor_certifications';
    protected $guarded = [];
}
