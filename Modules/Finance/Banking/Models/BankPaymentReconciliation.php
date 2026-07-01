<?php

namespace Modules\Finance\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\Banking\Enums\BankPaymentReconciliationStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class BankPaymentReconciliation extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, LogsActivity;

    protected $fillable = [
        'property_id',
        'controlled_bank_account_id',
        'controlled_bank_statement_line_id',
        'payment_execution_id',
        'posted_journal_entry_id',
        'currency_code',
        'payment_amount',
        'statement_amount',
        'difference_amount',
        'status',
        'reconciled_by',
        'reconciled_at',
        'source_identity_hash',
        'source_snapshot',
    ];

    protected $casts = [
        'payment_amount' => 'decimal:2',
        'statement_amount' => 'decimal:2',
        'difference_amount' => 'decimal:2',
        'status' => BankPaymentReconciliationStatusEnum::class,
        'reconciled_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(ControlledBankAccount::class, 'controlled_bank_account_id');
    }

    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(ControlledBankStatementLine::class, 'controlled_bank_statement_line_id');
    }

    public function paymentExecution(): BelongsTo
    {
        return $this->belongsTo(PaymentExecution::class, 'payment_execution_id');
    }

    public function postedJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'posted_journal_entry_id');
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
