<?php

namespace Modules\Operations\Purchasing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Modules\Operations\Purchasing\Enums\GoodsReceiptStatusEnum;

class GoodsReceipt extends Model
{
    use HasFactory, HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'received_date' => 'date',
        'status' => GoodsReceiptStatusEnum::class,
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(\Modules\Finance\Payables\Models\VendorInvoice::class);
    }

    protected static function newFactory()
    {
        return \Modules\Operations\Purchasing\Database\Factories\GoodsReceiptFactory::new();
    }
}
