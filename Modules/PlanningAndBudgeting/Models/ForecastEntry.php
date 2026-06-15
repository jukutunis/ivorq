<?php

namespace Modules\PlanningAndBudgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ForecastEntry extends Model
{
    use HasUlids;

    protected $fillable = [
        'forecast_version_id',
        'budget_category_id',
        'forecastable_type',
        'forecastable_id',
        'period_number',
        'amount',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    public function forecastVersion(): BelongsTo
    {
        return $this->belongsTo(ForecastVersion::class);
    }

    public function budgetCategory(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class);
    }

    public function forecastable(): MorphTo
    {
        return $this->morphTo();
    }
}
