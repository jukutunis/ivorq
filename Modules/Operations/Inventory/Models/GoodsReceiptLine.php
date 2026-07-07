<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class GoodsReceiptLine extends Model
{
    use HasUlid;
    use BelongsToProperty;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'goods_receipt_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'received_quantity' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderLine()
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function inventoryLocation()
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    public function inventoryUnit()
    {
        return $this->belongsTo(InventoryUnit::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stockMovement()
    {
        return $this->belongsTo(InventoryStockMovement::class, 'stock_movement_id');
    }
}
