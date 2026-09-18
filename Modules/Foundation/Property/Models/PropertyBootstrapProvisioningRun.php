<?php

namespace Modules\Foundation\Property\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningEnvironmentEnum;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningStatusEnum;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasUlid;

class PropertyBootstrapProvisioningRun extends Model
{
    use HasUlid;

    protected $table = 'property_bootstrap_provisioning_runs';

    protected $fillable = [];

    protected $casts = [
        'environment' => PropertyBootstrapProvisioningEnvironmentEnum::class,
        'status' => PropertyBootstrapProvisioningStatusEnum::class,
        'evidence' => 'array',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
    ];

    public function initiatingActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
