<?php

namespace Modules\Finance\Forecasting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\BelongsToProperty;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Forecast extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes, LogsActivity;

    protected $table = 'forecast_forecasts';
    protected $fillable = ['property_id', 'fiscal_year', 'name'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    public function versions()
    {
        return $this->hasMany(ForecastVersion::class, 'forecast_id');
    }
}
