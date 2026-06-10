<?php

namespace Modules\Finance\Payables\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Payables\Enums\VendorInvoiceStatusEnum;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\GoodsReceipt;

class VendorInvoice extends Model
{
    use HasFactory, HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'status' => VendorInvoiceStatusEnum::class,
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(VendorInvoiceLine::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function threeWayMatch(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ThreeWayMatch::class);
    }

    protected static function newFactory()
    {
        return \Modules\Finance\Payables\Database\Factories\VendorInvoiceFactory::new();
    }
}
