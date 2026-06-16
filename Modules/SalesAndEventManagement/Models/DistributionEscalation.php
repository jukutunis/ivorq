<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionEscalation extends Model
{
    use HasUlids;
    
    protected $table = 'beo_distribution_escalations';

    protected $fillable = [
        'beo_acknowledgement_id',
        'escalation_level',
        'escalated_to_role_id',
        'escalated_at',
    ];

    protected $casts = [
        'escalation_level' => 'integer',
        'escalated_at' => 'datetime',
    ];

    public function acknowledgement(): BelongsTo
    {
        return $this->belongsTo(BEOAcknowledgement::class, 'beo_acknowledgement_id');
    }
}
