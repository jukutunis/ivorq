<?php

namespace Modules\Operations\GeneralCashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CashReturnEvidence extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, LogsActivity;

    protected $table = 'cash_return_evidence';

    protected $fillable = [
        'payment_execution_id',
        'posted_journal_entry_id',
        'property_id',
        'vendor_id',
        'operational_gl_account_id',
        'currency_code',
        'return_amount',
        'observed_return_date',
        'source_reference',
        'recorded_by',
        'recorded_at',
        'source_identity_hash',
        'source_snapshot',
    ];

    protected $casts = [
        'return_amount' => 'decimal:2',
        'observed_return_date' => 'date',
        'recorded_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    public function paymentExecution(): BelongsTo
    {
        return $this->belongsTo(PaymentExecution::class, 'payment_execution_id');
    }

    public function postedJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'posted_journal_entry_id');
    }

    public function operationalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'operational_gl_account_id');
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
