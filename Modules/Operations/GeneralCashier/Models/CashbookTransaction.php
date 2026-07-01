<?php

namespace Modules\Operations\GeneralCashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashbookTransactionDirectionEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CashbookTransaction extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, LogsActivity;

    protected $table = 'cashbook_transactions';

    protected $fillable = [
        'property_id',
        'operational_gl_account_id',
        'currency_code',
        'amount',
        'direction',
        'posted_business_date',
        'journal_entry_id',
        'payment_execution_id',
        'source_module',
        'source_type',
        'source_id',
        'source_event',
        'source_identity_hash',
        'source_snapshot',
        'projected_by',
        'projected_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'direction' => CashbookTransactionDirectionEnum::class,
        'posted_business_date' => 'date',
        'source_snapshot' => 'array',
        'projected_at' => 'datetime',
    ];

    public function operationalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'operational_gl_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function paymentExecution(): BelongsTo
    {
        return $this->belongsTo(PaymentExecution::class, 'payment_execution_id');
    }

    public function projector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'projected_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
