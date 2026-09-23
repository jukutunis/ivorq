<?php

namespace Modules\Foundation\Authentication\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class OwnerRecoveryCode extends Model
{
    use HasUlid;

    protected $guarded = [];

    protected $casts = [
        'generation' => 'integer',
        'ordinal' => 'integer',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
