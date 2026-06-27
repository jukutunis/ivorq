<?php

namespace Modules\Finance\CostControl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasUlid;

class CostAuthorityEnrollmentScopeSnapshot extends Model
{
    use HasUlid;

    protected $table = 'cost_authority_enrollment_scope_snapshots';

    protected $fillable = [
        'enrollment_group_id',
        'location_id',
        'valuation_scope',
        'opening_quantity',
        'opening_carrying_value',
        'currency_code',
        'business_date',
        'financial_period_id',
        'source_reference',
        'evidence_timestamp',
    ];

    protected $casts = [
        'opening_quantity'       => 'decimal:4',
        'opening_carrying_value' => 'decimal:4',
        'business_date'          => 'date',
        'evidence_timestamp'     => 'datetime',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
    ];

    public function enrollmentGroup(): BelongsTo
    {
        return $this->belongsTo(CostAuthorityEnrollmentGroup::class, 'enrollment_group_id');
    }
}
