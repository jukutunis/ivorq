<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\User\Models\User;
use Modules\SalesAndEventManagement\Enums\AcknowledgementStatusEnum;

class BEOAcknowledgement extends Model
{
    use HasUlids;
    
    protected $table = 'beo_acknowledgements';

    protected $fillable = [
        'beo_distribution_id',
        'department_id',
        'user_id',
        'status',
        'viewed_at',
        'acknowledged_at',
        'sla_hours_configured',
        'sla_breach_at',
        'rejection_reason',
    ];

    protected $casts = [
        'status' => AcknowledgementStatusEnum::class,
        'viewed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'sla_breach_at' => 'datetime',
        'sla_hours_configured' => 'integer',
    ];

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(BEODistribution::class, 'beo_distribution_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(DistributionEscalation::class, 'beo_acknowledgement_id');
    }
}
