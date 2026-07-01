<?php

namespace Modules\Finance\Payables\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ApSettlementAllocation extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, LogsActivity;

    protected $fillable = [
        'property_id',
        'vendor_id',
        'currency_code',
        'ap_journal_entry_id',
        'payment_journal_entry_id',
        'payment_execution_id',
        'allocation_amount',
        'allocated_by',
        'allocated_at',
        'source_identity_hash',
        'source_snapshot',
    ];

    protected $casts = [
        'allocation_amount' => 'decimal:2',
        'allocated_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    public function apJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'ap_journal_entry_id');
    }

    public function paymentJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'payment_journal_entry_id');
    }

    public function paymentExecution(): BelongsTo
    {
        return $this->belongsTo(PaymentExecution::class, 'payment_execution_id');
    }

    public function allocator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
