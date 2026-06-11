<?php

namespace Modules\Finance\Forecasting\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;

class ForecastApproval extends Model
{
    use HasUlid;

    protected $table = 'forecast_forecast_approvals';
    protected $fillable = ['forecast_version_id', 'action_by_id', 'action', 'comments', 'action_at'];
    public $timestamps = true;

    protected $casts = [
        'action_at' => 'datetime',
    ];
}
