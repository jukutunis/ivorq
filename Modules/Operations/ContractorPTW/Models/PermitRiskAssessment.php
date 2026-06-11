<?php

namespace Modules\Operations\ContractorPTW\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class PermitRiskAssessment extends Model
{
    use HasUlid;

    protected $table = 'permit_risk_assessments';
    protected $guarded = [];
}
