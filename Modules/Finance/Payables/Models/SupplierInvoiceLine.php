<?php

namespace Modules\Finance\Payables\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class SupplierInvoiceLine extends Model
{
    use HasUlid, HasAuditColumns;

    protected $table = 'vendor_invoice_lines';

    protected $fillable = [
        'vendor_invoice_id',
        'purchase_order_line_id',
        'goods_receipt_line_id',
        'inventory_item_id',
        'description',
        'quantity',
        'unit_price',
        'line_total',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'vendor_invoice_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }
}
