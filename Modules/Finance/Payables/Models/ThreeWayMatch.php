<?php

namespace Modules\Finance\Payables\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Payables\Enums\MatchExceptionEnum;
use Modules\Finance\Payables\Enums\MatchStatusEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\GoodsReceipt;

class ThreeWayMatch extends Model
{
    use HasFactory, HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'status' => MatchStatusEnum::class,
        'exception_code' => MatchExceptionEnum::class,
        'total_quantity_variance' => 'decimal:4',
        'total_price_variance' => 'decimal:2',
        'total_amount_variance' => 'decimal:2',
    ];

    public function vendorInvoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ThreeWayMatchLine::class);
    }
}
