<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\SalesAndEventManagement\Enums\BEOStatusEnum;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class BEOIssueLog extends Model
{
    use HasUlids, LogsActivity;
    
    protected $table = 'beo_issue_logs';

    protected $fillable = [
        'company_id',
        'property_id',
        'function_id',
        'issue_number',
        'revision_number',
        'status',
        'snapshot_payload',
        'snapshot_hash',
        'previous_issue_id',
        'issued_at',
        'issued_by',
        'approved_at',
        'approved_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => BEOStatusEnum::class,
        'snapshot_payload' => 'array',
        'issued_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    public function eventFunction(): BelongsTo
    {
        return $this->belongsTo(EventFunction::class, 'function_id');
    }

    public function previousIssue(): BelongsTo
    {
        return $this->belongsTo(BEOIssueLog::class, 'previous_issue_id');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(BEOAcknowledgement::class, 'beo_issue_log_id');
    }
}
