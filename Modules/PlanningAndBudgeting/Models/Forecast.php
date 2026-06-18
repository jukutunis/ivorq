<?php

namespace Modules\PlanningAndBudgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\PlanningAndBudgeting\Enums\ForecastTypeEnum;
use Modules\PlanningAndBudgeting\Enums\ForecastSourceTypeEnum;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Forecast extends Model
{
    use HasUlids, LogsActivity;

    protected $fillable = [
        'company_id',
        'property_id',
        'forecast_name',
        'forecast_type',
        'forecast_source_type',
        'base_budget_version_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'forecast_type' => ForecastTypeEnum::class,
        'forecast_source_type' => ForecastSourceTypeEnum::class,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    public function baseBudgetVersion(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'base_budget_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ForecastVersion::class);
    }
}
