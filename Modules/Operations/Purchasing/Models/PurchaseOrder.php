<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Foundation\Approval\Contracts\ApprovableContract;

class PurchaseOrder extends Model implements ApprovableContract
{
    use HasFactory, HasUlids, SoftDeletes, BelongsToProperty, HasAuditColumns;

    protected $guarded = ['id'];

    protected $casts = [
        'issue_date' => 'date',
        'expected_delivery_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'received_total' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'status' => PurchaseOrderStatusEnum::class,
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(\Modules\Finance\Payables\Models\VendorInvoice::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    protected static function newFactory()
    {
        return \Modules\Operations\Purchasing\Database\Factories\PurchaseOrderFactory::new();
    }

    // ApprovableContract Implementation

    public function getApprovableType(): string
    {
        return static::class;
    }

    public function getApprovableId(): string
    {
        return $this->id;
    }

    public function getPropertyId(): string
    {
        return $this->property_id;
    }

    public function getDepartmentId(): ?string
    {
        // PO might not have a direct department, check PR
        return $this->purchaseRequest?->department_id;
    }

    public function getApprovalAmount(): float
    {
        return (float) $this->total_amount;
    }

    public function markAsApproved(): void
    {
        $this->update(['status' => PurchaseOrderStatusEnum::Approved]);
    }

    public function markAsRejected(?string $reason = null): void
    {
        $this->update(['status' => PurchaseOrderStatusEnum::Rejected, 'remarks' => $reason]);
    }
}
