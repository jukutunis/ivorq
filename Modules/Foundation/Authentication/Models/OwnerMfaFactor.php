<?php

namespace Modules\Foundation\Authentication\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class OwnerMfaFactor extends Model
{
    use HasUlid;

    protected $guarded = [];

    protected $hidden = ['encrypted_secret'];

    protected $casts = [
        'last_accepted_counter' => 'integer',
        'enrollment_started_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
