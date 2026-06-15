<?php

namespace Modules\PlanningAndBudgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\PlanningAndBudgeting\Enums\BudgetVersionStatusEnum;

class BudgetVersion extends Model
{
    use HasUlids;

    protected $fillable = [
        'company_id',
        'property_id',
        'budget_scenario_id',
        'version_number',
        'status',
        'change_reason',
        'created_by',
        'updated_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'status' => BudgetVersionStatusEnum::class,
        'approved_at' => 'datetime',
    ];

    public function budgetScenario(): BelongsTo
    {
        return $this->belongsTo(BudgetScenario::class);
    }
}
