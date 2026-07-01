<?php

namespace Modules\Operations\GeneralCashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CashSupplierPaymentReversalExecution extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, LogsActivity;

    protected $fillable = [
        'cash_return_evidence_id',
        'original_payment_execution_id',
        'original_posted_journal_entry_id',
        'property_id',
        'vendor_id',
        'operational_gl_account_id',
        'currency_code',
        'reversal_amount',
        'reversed_by',
        'reversed_at',
        'source_identity_hash',
        'source_snapshot',
    ];

    protected $casts = [
        'reversal_amount' => 'decimal:2',
        'reversed_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    public function cashReturnEvidence(): BelongsTo
    {
        return $this->belongsTo(CashReturnEvidence::class, 'cash_return_evidence_id');
    }

    public function originalPaymentExecution(): BelongsTo
    {
        return $this->belongsTo(PaymentExecution::class, 'original_payment_execution_id');
    }

    public function originalPostedJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'original_posted_journal_entry_id');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
