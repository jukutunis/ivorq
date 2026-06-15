<?php

namespace Modules\PlanningAndBudgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BudgetEntry extends Model
{
    use HasUlids;

    protected $fillable = [
        'company_id',
        'property_id',
        'budget_version_id',
        'budget_category_id',
        'budgetable_type',
        'budgetable_id',
        'period_number',
        'amount',
        'is_calculated',
        'override_reason',
        'override_by',
        'override_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'is_calculated' => 'boolean',
        'override_at' => 'datetime',
    ];

    public function budgetVersion(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class);
    }

    public function budgetCategory(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class);
    }

    public function budgetable(): MorphTo
    {
        return $this->morphTo();
    }
}
