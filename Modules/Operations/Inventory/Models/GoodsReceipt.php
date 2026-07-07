<?php

namespace Modules\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\GoodsReceiptStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use RuntimeException;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class GoodsReceipt extends Model
{
    use HasUlid;
    use BelongsToProperty;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'goods_receipts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => GoodsReceiptStatusEnum::class,
            'received_at' => 'datetime',
            'posted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (GoodsReceipt $receipt) {
            $original = $receipt->getOriginal('status');
            if ($original instanceof GoodsReceiptStatusEnum) {
                $original = $original->value;
            }
            if ($original === GoodsReceiptStatusEnum::Posted->value) {
                throw new RuntimeException('Posted Goods Receipt is immutable and cannot be updated.');
            }
        });

        static::deleting(function (GoodsReceipt $receipt) {
            $original = $receipt->getOriginal('status');
            if ($original instanceof GoodsReceiptStatusEnum) {
                $original = $original->value;
            }
            if ($original === GoodsReceiptStatusEnum::Posted->value) {
                throw new RuntimeException('Posted Goods Receipt is immutable and cannot be deleted.');
            }
        });
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }
}
