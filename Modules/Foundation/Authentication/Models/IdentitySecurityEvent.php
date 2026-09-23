<?php

namespace Modules\Foundation\Authentication\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class IdentitySecurityEvent extends Model
{
    use HasUlid;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
