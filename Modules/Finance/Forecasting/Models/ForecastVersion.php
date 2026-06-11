<?php

namespace Modules\Finance\Forecasting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Modules\Finance\Forecasting\Enums\ForecastVersionStatusEnum;

class ForecastVersion extends Model
{
    use HasUlid, HasAuditColumns, SoftDeletes;

    protected $table = 'forecast_forecast_versions';
    protected $fillable = ['forecast_id', 'version_number', 'status'];

    protected $casts = [
        'status' => ForecastVersionStatusEnum::class,
    ];

    public function forecast()
    {
        return $this->belongsTo(Forecast::class, 'forecast_id');
    }

    public function lines()
    {
        return $this->hasMany(ForecastLine::class, 'forecast_version_id');
    }
}
