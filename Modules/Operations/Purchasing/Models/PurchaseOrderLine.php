<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Shared\Traits\HasAuditColumns;

class PurchaseOrderLine extends Model
{
    use HasFactory, HasUlids, SoftDeletes, HasAuditColumns;

    protected $guarded = ['id'];

    protected $casts = [
        'ordered_quantity' => 'decimal:3',
        'received_quantity' => 'decimal:3',
        'invoiced_quantity' => 'decimal:3',
        'receiving_tolerance_percent' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function getRemainingQuantityAttribute(): float
    {
        return max(0, $this->ordered_quantity - $this->received_quantity);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseRequestLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestLine::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class);
    }

    protected static function newFactory()
    {
        return \Modules\Operations\Purchasing\Database\Factories\PurchaseOrderLineFactory::new();
    }
}
