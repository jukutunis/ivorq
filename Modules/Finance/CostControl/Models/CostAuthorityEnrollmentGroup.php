<?php

namespace Modules\Finance\CostControl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Shared\Traits\HasUlid;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;

class CostAuthorityEnrollmentGroup extends Model
{
    use HasUlid;

    protected $table = 'cost_authority_enrollment_groups';

    protected $fillable = [
        'property_id',
        'item_id',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejected_reason',
        'superseded_by',
        'superseded_at',
        'superseded_reason',
        'enrolled_at',
    ];

    protected $casts = [
        'status'       => CostAuthorityEnrollmentStatusEnum::class,
        'approved_at'  => 'datetime',
        'rejected_at'  => 'datetime',
        'superseded_at' => 'datetime',
        'enrolled_at'  => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function scopeSnapshots(): HasMany
    {
        return $this->hasMany(CostAuthorityEnrollmentScopeSnapshot::class, 'enrollment_group_id');
    }
}
