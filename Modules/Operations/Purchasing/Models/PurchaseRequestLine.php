<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Purchasing\Database\Factories\PurchaseRequestLineFactory;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class PurchaseRequestLine extends Model
{
    use HasAuditColumns, HasFactory, HasUlid, SoftDeletes;

    protected $table = 'purchase_request_lines';

    protected $fillable = [
        'purchase_request_id',
        'inventory_item_id',
        'description',
        'quantity',
        'unit_id',
        'estimated_unit_cost',
        'estimated_total_cost',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'estimated_unit_cost' => 'decimal:2',
        'estimated_total_cost' => 'decimal:2',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'unit_id');
    }

    protected static function newFactory()
    {
        return PurchaseRequestLineFactory::new();
    }
}
