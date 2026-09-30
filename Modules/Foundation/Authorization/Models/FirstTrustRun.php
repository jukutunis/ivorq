<?php

namespace Modules\Foundation\Authorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authorization\Enums\FirstTrustRunStatus;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasUlid;

class FirstTrustRun extends Model
{
    use HasUlid;

    protected $guarded = [];

    protected $casts = [
        'status' => FirstTrustRunStatus::class,
        'reservation_reserved_at' => 'immutable_datetime',
        'reservation_commit_deadline' => 'immutable_datetime',
        'reservation_recovery_deadline' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'foundation_committed_at' => 'immutable_datetime',
        'external_consumed_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function ownerActivation(): BelongsTo
    {
        return $this->belongsTo(OwnerActivation::class);
    }
}
