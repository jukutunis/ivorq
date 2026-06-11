<?php

namespace Modules\Operations\ContractorPTW\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContractorWorkerGlobal extends Model
{
    use HasUlid;
    use SoftDeletes;

    protected $table = 'contractor_worker_globals';
    protected $guarded = [];
}
