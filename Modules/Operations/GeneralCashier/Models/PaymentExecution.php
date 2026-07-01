<?php

namespace Modules\Operations\GeneralCashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\Banking\Models\ControlledBankAccount;
use Modules\Finance\Banking\Models\ControlledBankStatementLine;
use Modules\Finance\Payables\Models\PaymentProposal;
use Modules\Finance\Payables\Models\PaymentProposalItem;
use Modules\Foundation\User\Models\User;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PaymentExecution extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, LogsActivity;

    protected $table = 'payment_executions';

    protected $fillable = [
        'property_id',
        'vendor_id',
        'payment_proposal_id',
        'payment_proposal_item_id',
        'payment_intent_key',
        'source_journal_entry_id',
        'source_journal_candidate_id',
        'supplier_invoice_id',
        'cashier_session_id',
        'cashier_payment_instrument_id',
        'operational_gl_account_id',
        'controlled_bank_account_id',
        'controlled_bank_statement_line_id',
        'currency_code',
        'source_amount',
        'executed_by',
        'executed_at',
        'source_snapshot',
    ];

    protected $casts = [
        'source_amount' => 'decimal:2',
        'executed_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(PaymentProposal::class, 'payment_proposal_id');
    }

    public function proposalItem(): BelongsTo
    {
        return $this->belongsTo(PaymentProposalItem::class, 'payment_proposal_item_id');
    }

    public function sourceJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'source_journal_entry_id');
    }

    public function cashierSession(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class, 'cashier_session_id');
    }

    public function cashierPaymentInstrument(): BelongsTo
    {
        return $this->belongsTo(CashierPaymentInstrument::class, 'cashier_payment_instrument_id');
    }

    public function controlledBankAccount(): BelongsTo
    {
        return $this->belongsTo(ControlledBankAccount::class, 'controlled_bank_account_id');
    }

    public function controlledBankStatementLine(): BelongsTo
    {
        return $this->belongsTo(ControlledBankStatementLine::class, 'controlled_bank_statement_line_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
