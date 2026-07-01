<?php

namespace Modules\Operations\GeneralCashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashReconciliationStatusEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CashReconciliation extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, LogsActivity;

    protected $fillable = [
        'cash_reconciliation_baseline_id',
        'ending_cash_count_evidence_id',
        'property_id',
        'operational_gl_account_id',
        'currency_code',
        'scope_start_exclusive_date',
        'scope_end_inclusive_date',
        'baseline_amount',
        'cashbook_inflow_amount',
        'cashbook_outflow_amount',
        'expected_amount',
        'observed_amount',
        'difference_amount',
        'status',
        'reconciled_by',
        'reconciled_at',
        'source_identity_hash',
        'source_snapshot',
    ];

    protected $casts = [
        'scope_start_exclusive_date' => 'date',
        'scope_end_inclusive_date' => 'date',
        'baseline_amount' => 'decimal:2',
        'cashbook_inflow_amount' => 'decimal:2',
        'cashbook_outflow_amount' => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'observed_amount' => 'decimal:2',
        'difference_amount' => 'decimal:2',
        'status' => CashReconciliationStatusEnum::class,
        'reconciled_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(CashReconciliationBaseline::class, 'cash_reconciliation_baseline_id');
    }

    public function endingCashCountEvidence(): BelongsTo
    {
        return $this->belongsTo(CashCountEvidence::class, 'ending_cash_count_evidence_id');
    }

    public function operationalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'operational_gl_account_id');
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
