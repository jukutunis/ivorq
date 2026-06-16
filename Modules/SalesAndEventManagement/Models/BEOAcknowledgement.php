<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Department\Models\Department;

class BEOAcknowledgement extends Model
{
    use HasUlids;
    
    protected $table = 'beo_acknowledgements';

    protected $fillable = [
        'beo_issue_log_id',
        'department_id',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public function beoIssueLog(): BelongsTo
    {
        return $this->belongsTo(BEOIssueLog::class, 'beo_issue_log_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
