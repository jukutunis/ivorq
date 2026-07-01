<?php

namespace Modules\Finance\Payables\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class PaymentProposalItem extends Model
{
    use HasUlid, HasAuditColumns;

    protected $fillable = [
        'payment_proposal_id',
        'property_id',
        'source_journal_entry_id',
        'source_journal_candidate_id',
        'supplier_invoice_id',
        'vendor_id',
        'currency_code',
        'source_amount',
        'original_source_amount',
        'requested_payment_amount',
        'is_active',
        'source_snapshot',
    ];

    protected $casts = [
        'source_amount' => 'decimal:2',
        'original_source_amount' => 'decimal:2',
        'requested_payment_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'source_snapshot' => 'array',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(PaymentProposal::class, 'payment_proposal_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function sourceJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'source_journal_entry_id');
    }

    public function sourceCandidate(): BelongsTo
    {
        return $this->belongsTo(JournalCandidate::class, 'source_journal_candidate_id');
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
