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

class VendorInvoiceLine extends Model
{
    use HasFactory, HasUlid, HasAuditColumns;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class, 'vendor_invoice_id');
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

    protected static function newFactory()
    {
        return \Modules\Finance\Payables\Database\Factories\VendorInvoiceLineFactory::new();
    }
}
