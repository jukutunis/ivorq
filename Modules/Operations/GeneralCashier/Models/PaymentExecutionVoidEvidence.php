<?php

namespace Modules\Operations\GeneralCashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\User\Models\User;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PaymentExecutionVoidEvidence extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, LogsActivity;

    protected $table = 'payment_execution_void_evidence';

    protected $fillable = [
        'payment_execution_id',
        'property_id',
        'vendor_id',
        'payment_proposal_id',
        'payment_proposal_item_id',
        'source_journal_entry_id',
        'source_journal_candidate_id',
        'supplier_invoice_id',
        'operational_gl_account_id',
        'currency_code',
        'source_amount',
        'void_reason',
        'voided_by',
        'voided_at',
        'source_identity_hash',
        'source_snapshot',
    ];

    protected $casts = [
        'source_amount' => 'decimal:2',
        'voided_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    public function paymentExecution(): BelongsTo
    {
        return $this->belongsTo(PaymentExecution::class, 'payment_execution_id');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
