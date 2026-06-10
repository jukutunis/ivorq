<?php

namespace Modules\Finance\Payables\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Payables\Enums\PaymentMethodEnum;
use Modules\Finance\Payables\Enums\PaymentVoucherStatusEnum;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class PaymentVoucher extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory;

    protected $guarded = [];

    protected $casts = [
        'payment_date' => 'date',
        'total_amount' => 'decimal:2',
        'payment_method' => PaymentMethodEnum::class,
        'status' => PaymentVoucherStatusEnum::class,
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PaymentVoucherLine::class);
    }
}
