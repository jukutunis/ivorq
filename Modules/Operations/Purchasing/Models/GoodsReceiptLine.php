<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;

class GoodsReceiptLine extends Model
{
    use HasFactory, HasUlid, HasAuditColumns, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity_received' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    protected static function newFactory()
    {
        return \Modules\Operations\Purchasing\Database\Factories\GoodsReceiptLineFactory::new();
    }
}
