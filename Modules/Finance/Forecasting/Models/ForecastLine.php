<?php

namespace Modules\Finance\Forecasting\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\HasAuditColumns;
use Modules\Finance\GeneralLedger\Models\Account;

class ForecastLine extends Model
{
    use HasUlid, HasAuditColumns;

    protected $table = 'forecast_forecast_lines';
    protected $fillable = ['forecast_version_id', 'department_id', 'account_id', 'period_month', 'amount'];

    public function version()
    {
        return $this->belongsTo(ForecastVersion::class, 'forecast_version_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
