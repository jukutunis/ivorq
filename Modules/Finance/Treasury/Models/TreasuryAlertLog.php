<?php

namespace Modules\Finance\Treasury\Models;

use Illuminate\Database\Eloquent\Model;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;
use Modules\Finance\Treasury\Enums\TreasuryAlertSeverityEnum;

class TreasuryAlertLog extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'treasury_alert_logs';
    protected $fillable = ['property_id', 'alert_type', 'severity', 'message', 'context_data', 'logged_at'];

    protected $casts = [
        'severity' => TreasuryAlertSeverityEnum::class,
        'context_data' => 'array',
        'logged_at' => 'datetime',
    ];
}
