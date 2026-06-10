<?php

namespace Modules\Finance\Payables\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;
use Modules\Operations\Purchasing\Models\GoodsReceiptLine;
use Modules\Operations\Inventory\Models\InventoryItem;

class ThreeWayMatchLine extends Model
{
    use HasFactory, HasUlid, HasAuditColumns;

    protected $guarded = [];

    protected $casts = [
        'po_quantity' => 'decimal:4',
        'po_price' => 'decimal:2',
        'grn_quantity' => 'decimal:4',
        'invoice_quantity' => 'decimal:4',
        'invoice_price' => 'decimal:2',
        'quantity_variance' => 'decimal:4',
        'price_variance' => 'decimal:2',
        'amount_variance' => 'decimal:2',
    ];

    public function threeWayMatch(): BelongsTo
    {
        return $this->belongsTo(ThreeWayMatch::class);
    }

    public function vendorInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(VendorInvoiceLine::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
