<?php

namespace Modules\PlanningAndBudgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\PlanningAndBudgeting\Enums\BenchmarkTargetStatusEnum;

class BenchmarkTarget extends Model
{
    use HasUlids;

    protected $fillable = [
        'company_id',
        'property_id',
        'benchmark_template_id',
        'budget_cycle_id',
        'target_value',
        'adopted_value',
        'status',
        'justification',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'target_value' => 'decimal:4',
        'adopted_value' => 'decimal:4',
        'status' => BenchmarkTargetStatusEnum::class,
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(BenchmarkTemplate::class, 'benchmark_template_id');
    }

    public function budgetCycle(): BelongsTo
    {
        return $this->belongsTo(BudgetCycle::class);
    }
}
