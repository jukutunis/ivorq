<?php

namespace Modules\PlanningAndBudgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\PlanningAndBudgeting\Enums\OperationalMetricEnum;

class OperationalAssumption extends Model
{
    use HasUlids;

    protected $fillable = [
        'company_id',
        'property_id',
        'budget_version_id',
        'forecast_version_id',
        'metric_type',
        'period_number',
        'value',
        'department_id',
        'outlet_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'metric_type' => OperationalMetricEnum::class,
    ];

    public function budgetVersion(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class);
    }

    public function forecastVersion(): BelongsTo
    {
        return $this->belongsTo(ForecastVersion::class);
    }
}
