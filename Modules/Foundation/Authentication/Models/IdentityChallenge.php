<?php

namespace Modules\Foundation\Authentication\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class IdentityChallenge extends Model
{
    use HasUlid;

    protected $guarded = [];

    protected $casts = [
        'auth_epoch' => 'integer',
        'failed_attempts' => 'integer',
        'max_attempts' => 'integer',
        'password_verified_at' => 'datetime',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'credential_issued_at' => 'datetime',
    ];
}
