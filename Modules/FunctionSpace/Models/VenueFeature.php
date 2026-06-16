<?php

namespace Modules\FunctionSpace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasAuditColumns;

class VenueFeature extends Model
{
    use HasUlids, SoftDeletes, HasAuditColumns;

    protected $fillable = [
        'company_id',
        'name',
        'icon',
        'is_active',
    ];
}
