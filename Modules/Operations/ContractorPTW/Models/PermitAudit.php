<?php

namespace Modules\Operations\ContractorPTW\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class PermitAudit extends Model
{
    use HasUlid;
    use BelongsToProperty;

    protected $table = 'permit_audits';
    protected $guarded = [];
}
