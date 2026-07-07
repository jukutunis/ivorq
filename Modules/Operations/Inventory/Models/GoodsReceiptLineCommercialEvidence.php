<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;
use RuntimeException;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class GoodsReceiptLineCommercialEvidence extends Model
{
    use HasUlid;
    use BelongsToProperty;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'goods_receipt_line_commercial_evidences';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'purchase_order_unit_cost_snapshot' => 'decimal:2',
            'purchase_order_exchange_rate_snapshot' => 'decimal:4',
            'captured_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (GoodsReceiptLineCommercialEvidence $evidence) {
            throw new RuntimeException('Receipt commercial evidence is immutable and cannot be updated.');
        });

        static::deleting(function (GoodsReceiptLineCommercialEvidence $evidence) {
            throw new RuntimeException('Receipt commercial evidence is immutable and cannot be deleted.');
        });
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function goodsReceiptLine()
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseOrderLine()
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function inventoryUnit()
    {
        return $this->belongsTo(InventoryUnit::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
