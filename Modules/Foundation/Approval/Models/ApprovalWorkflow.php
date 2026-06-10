<?php

namespace Modules\Foundation\Approval\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class ApprovalWorkflow extends Model
{
    use HasFactory, HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $table = 'approval_workflows';

    protected $fillable = [
        'property_id',
        'workflow_name',
        'module',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class, 'workflow_id')->orderBy('sequence_no');
    }

    protected static function newFactory()
    {
        return \Modules\Foundation\Approval\Database\Factories\ApprovalWorkflowFactory::new();
    }
}
