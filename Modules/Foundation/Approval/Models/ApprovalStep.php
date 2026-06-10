<?php

namespace Modules\Foundation\Approval\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class ApprovalStep extends Model
{
    use HasFactory, HasUlid, HasAuditColumns, SoftDeletes;

    protected $table = 'approval_steps';

    protected $fillable = [
        'workflow_id',
        'sequence_no',
        'role_name',
        'permission_name',
        'approval_limit',
        'currency_code',
        'is_required',
    ];

    protected $casts = [
        'sequence_no' => 'integer',
        'approval_limit' => 'decimal:2',
        'is_required' => 'boolean',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    protected static function newFactory()
    {
        return \Modules\Foundation\Approval\Database\Factories\ApprovalStepFactory::new();
    }
}
