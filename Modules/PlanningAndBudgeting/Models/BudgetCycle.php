<?php

namespace Modules\PlanningAndBudgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Modules\PlanningAndBudgeting\Enums\BudgetCycleStatusEnum;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetCycle extends Model
{
    use HasUlids;

    protected $fillable = [
        'company_id',
        'property_id',
        'fiscal_year',
        'cycle_name',
        'start_date',
        'end_date',
        'status',
        'created_by',
        'updated_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => BudgetCycleStatusEnum::class,
        'approved_at' => 'datetime',
    ];

    public function scenarios(): HasMany
    {
        return $this->hasMany(BudgetScenario::class);
    }
}
