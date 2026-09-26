<?php

namespace Modules\Foundation\Authentication\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\Authentication\Enums\OwnerActivationStatus;
use Shared\Traits\HasUlid;

class OwnerActivation extends Model
{
    use HasUlid;

    protected $guarded = [];

    protected $casts = [
        'status' => OwnerActivationStatus::class,
        'version' => 'integer',
        'invited_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'password_established_at' => 'datetime',
        'mfa_enrollment_started_at' => 'datetime',
        'mfa_enrolled_at' => 'datetime',
        'activated_at' => 'datetime',
    ];
}
