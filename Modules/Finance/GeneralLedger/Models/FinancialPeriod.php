<?php

namespace Modules\Finance\GeneralLedger\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;

class FinancialPeriod extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory;

    protected $table = 'gl_financial_periods';

    protected $fillable = [
        'property_id',
        'period_year',
        'period_month',
        'status',
        'opened_at',
        'closing_snapshot_at',
        'closed_at',
        'opened_by',
        'closed_by',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'period_month' => 'integer',
        'status' => FinancialPeriodStatusEnum::class,
        'opened_at' => 'datetime',
        'closing_snapshot_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
