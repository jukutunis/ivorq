<?php

namespace Modules\Operations\ContractorPTW\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class PermitAttachment extends Model
{
    use HasUlid;

    protected $table = 'permit_attachments';
    protected $guarded = [];
}
