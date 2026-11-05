<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\GoodsReceiptExceptionResolutionTypeEnum;
use Modules\Operations\Inventory\Enums\GoodsReceiptExceptionStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;
use RuntimeException;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class GoodsReceiptLineException extends Model
{
    use BelongsToProperty, HasUlid;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => GoodsReceiptExceptionStatusEnum::class,
            'resolution_type' => GoodsReceiptExceptionResolutionTypeEnum::class,
            'observed_quantity' => 'decimal:3',
            'accepted_quantity' => 'decimal:3',
            'reported_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (GoodsReceiptLineException $exception) {
            if ($exception->getOriginal('status') === GoodsReceiptExceptionStatusEnum::Resolved->value) {
                throw new RuntimeException('Resolved Goods Receipt exception evidence is immutable.');
            }

            $immutable = [
                'property_id', 'goods_receipt_id', 'goods_receipt_line_id', 'purchase_order_id',
                'purchase_order_line_id', 'discrepancy_type', 'inspection_result', 'observed_quantity',
                'reason', 'notes', 'reported_by', 'reported_at', 'idempotency_key', 'source_fingerprint',
            ];

            if ($exception->isDirty($immutable)) {
                throw new RuntimeException('Goods Receipt exception source evidence is immutable.');
            }
        });

        static::deleting(fn () => throw new RuntimeException('Goods Receipt exception evidence cannot be deleted.'));
    }

    public function property() { return $this->belongsTo(Property::class); }
    public function goodsReceipt() { return $this->belongsTo(GoodsReceipt::class); }
    public function goodsReceiptLine() { return $this->belongsTo(GoodsReceiptLine::class); }
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function purchaseOrderLine() { return $this->belongsTo(PurchaseOrderLine::class); }
    public function reporter() { return $this->belongsTo(User::class, 'reported_by'); }
    public function resolver() { return $this->belongsTo(User::class, 'resolved_by'); }
}
