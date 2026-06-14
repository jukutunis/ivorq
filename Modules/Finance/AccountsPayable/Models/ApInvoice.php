<?php

namespace Modules\Finance\AccountsPayable\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceStatusEnum;
use Modules\Finance\AccountsPayable\Enums\ApInvoiceTypeEnum;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Finance\AccountsPayable\Database\Factories\ApInvoiceFactory;

class ApInvoice extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes, HasFactory;

    protected static function newFactory()
    {
        return ApInvoiceFactory::new();
    }

    protected $table = 'ap_invoices';

    protected $fillable = [
        'property_id',
        'vendor_id',
        'invoice_type',
        'status',
        'vendor_invoice_number',
        'invoice_date',
        'due_date',
        'subtotal_amount',
        'tax_amount',
        'grand_total_amount',
        'remarks',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'posted_by',
        'posted_at',
        'voided_by',
        'voided_at',
    ];

    protected $casts = [
        'status' => ApInvoiceStatusEnum::class,
        'invoice_type' => ApInvoiceTypeEnum::class,
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal_amount' => 'decimal:3',
        'tax_amount' => 'decimal:3',
        'grand_total_amount' => 'decimal:3',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ApInvoiceLine::class, 'invoice_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
