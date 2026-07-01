<?php

namespace Modules\Operations\GeneralCashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Foundation\User\Models\User;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CashReconciliationBaseline extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, LogsActivity;

    protected $table = 'cash_reconciliation_baselines';

    protected $fillable = [
        'cash_count_evidence_id',
        'property_id',
        'operational_gl_account_id',
        'currency_code',
        'baseline_amount',
        'cashbook_boundary_posted_business_date',
        'baseline_by',
        'baseline_at',
        'source_identity_hash',
        'source_snapshot',
    ];

    protected $casts = [
        'baseline_amount' => 'decimal:2',
        'cashbook_boundary_posted_business_date' => 'date',
        'baseline_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    public function cashCountEvidence(): BelongsTo
    {
        return $this->belongsTo(CashCountEvidence::class, 'cash_count_evidence_id');
    }

    public function operationalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'operational_gl_account_id');
    }

    public function baselineActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'baseline_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
