<?php

namespace Modules\PlanningAndBudgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\PlanningAndBudgeting\Enums\RevenueMetricEnum;

class RevenueAssumption extends Model
{
    use HasUlids;

    protected $fillable = [
        'company_id',
        'property_id',
        'budget_version_id',
        'metric_type',
        'period_number',
        'value',
        'room_type_id',
        'market_segment_id',
        'channel_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'metric_type' => RevenueMetricEnum::class,
    ];

    public function budgetVersion(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class);
    }
}
