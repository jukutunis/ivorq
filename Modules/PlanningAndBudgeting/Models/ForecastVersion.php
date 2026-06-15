<?php

namespace Modules\PlanningAndBudgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\PlanningAndBudgeting\Enums\ForecastStatusEnum;
use Modules\PlanningAndBudgeting\Enums\AccuracyStatusEnum;

class ForecastVersion extends Model
{
    use HasUlids;

    protected $fillable = [
        'forecast_id',
        'version_number',
        'status',
        'start_date',
        'end_date',
        'accuracy_status',
        'accuracy_calculated_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => ForecastStatusEnum::class,
        'accuracy_status' => AccuracyStatusEnum::class,
        'accuracy_calculated_at' => 'datetime',
    ];

    public function forecast(): BelongsTo
    {
        return $this->belongsTo(Forecast::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ForecastEntry::class);
    }
}
