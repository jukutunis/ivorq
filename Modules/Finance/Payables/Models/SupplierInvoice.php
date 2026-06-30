<?php

namespace Modules\Finance\Payables\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class SupplierInvoice extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    public const STATUS_REGISTERED = 'REGISTERED';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    protected $table = 'vendor_invoices';

    protected $fillable = [
        'property_id',
        'vendor_id',
        'purchase_order_id',
        'goods_receipt_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'currency_code',
        'status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'grand_total',
        'remarks',
        'exception_resolved_by',
        'exception_resolved_at',
        'exception_resolution_reason',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'exception_resolved_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class, 'vendor_invoice_id');
    }

    public function threeWayMatch(): HasOne
    {
        return $this->hasOne(ThreeWayMatch::class, 'vendor_invoice_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function exceptionResolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exception_resolved_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
