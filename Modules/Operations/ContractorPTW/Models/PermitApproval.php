<?php

namespace Modules\Operations\ContractorPTW\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class PermitApproval extends Model
{
    use HasUlid;

    protected $table = 'permit_approvals';
    protected $guarded = [];
}
