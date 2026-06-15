<?php

namespace Modules\PlanningAndBudgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetScenario extends Model
{
    use HasUlids;

    protected $fillable = [
        'company_id',
        'property_id',
        'budget_cycle_id',
        'name',
        'description',
        'created_by',
        'updated_by',
    ];

    public function budgetCycle(): BelongsTo
    {
        return $this->belongsTo(BudgetCycle::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BudgetVersion::class);
    }
}
