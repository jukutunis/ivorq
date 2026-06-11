<?php

namespace Modules\Finance\GeneralLedger\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Modules\Finance\GeneralLedger\Enums\PackageStatusEnum;

class FinancialPackageSnapshot extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory;

    protected $table = 'gl_financial_package_snapshots';

    protected $fillable = [
        'property_id',
        'period_year',
        'period_month',
        'package_status',
        'snapshot_json',
        'generated_at',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'period_month' => 'integer',
        'package_status' => PackageStatusEnum::class,
        'snapshot_json' => 'array',
        'generated_at' => 'datetime',
    ];
}
