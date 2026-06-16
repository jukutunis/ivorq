<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionAuditTrail extends Model
{
    use HasUlids;
    
    protected $table = 'beo_distribution_audit_trails';

    protected $fillable = [
        'distribution_id',
        'event_type',
        'old_value',
        'new_value',
        'performed_by',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
    ];

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(BEODistribution::class, 'distribution_id');
    }
}
