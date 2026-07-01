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

class CashCountEvidence extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, LogsActivity;

    protected $table = 'cash_count_evidence';

    protected $fillable = [
        'property_id',
        'operational_gl_account_id',
        'currency_code',
        'observed_amount',
        'observed_count_date',
        'source_reference',
        'counted_by',
        'recorded_by',
        'recorded_at',
        'source_identity_hash',
        'source_snapshot',
    ];

    protected $casts = [
        'observed_amount' => 'decimal:2',
        'observed_count_date' => 'date',
        'recorded_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    public function operationalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'operational_gl_account_id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
