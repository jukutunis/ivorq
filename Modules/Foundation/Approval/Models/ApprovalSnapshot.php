<?php

namespace Modules\Foundation\Approval\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Approval\Enums\ApprovalActionEnum;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasUlid;

class ApprovalSnapshot extends Model
{
    use HasUlid;

    protected $table = 'approval_snapshots';

    // Disable timestamps as we only have approved_at
    public $timestamps = false;

    protected $fillable = [
        'reference_type',
        'reference_id',
        'workflow_id',
        'sequence_no',
        'approver_id',
        'approver_name',
        'role_name',
        'approval_limit',
        'action',
        'remarks',
        'approved_at',
    ];

    protected $casts = [
        'sequence_no' => 'integer',
        'approval_limit' => 'decimal:2',
        'action' => ApprovalActionEnum::class,
        'approved_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
