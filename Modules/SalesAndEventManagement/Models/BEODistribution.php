<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\SalesAndEventManagement\Enums\DistributionStatusEnum;
use Modules\SalesAndEventManagement\Enums\DistributionSeverityEnum;

class BEODistribution extends Model
{
    use HasUlids;
    
    protected $table = 'beo_distributions';

    protected $fillable = [
        'company_id',
        'property_id',
        'beo_issue_log_id',
        'status',
        'severity',
        'distributed_at',
        'distributed_by',
    ];

    protected $casts = [
        'status' => DistributionStatusEnum::class,
        'severity' => DistributionSeverityEnum::class,
        'distributed_at' => 'datetime',
    ];

    public function beoIssueLog(): BelongsTo
    {
        return $this->belongsTo(BEOIssueLog::class, 'beo_issue_log_id');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(BEOAcknowledgement::class, 'beo_distribution_id');
    }

    public function auditTrails(): HasMany
    {
        return $this->hasMany(DistributionAuditTrail::class, 'distribution_id');
    }
}
