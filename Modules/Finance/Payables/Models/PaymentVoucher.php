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
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PaymentVoucher extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory, LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'payment_date' => 'date',
        'total_amount' => 'decimal:2',
        'payment_method' => PaymentMethodEnum::class,
        'status' => PaymentVoucherStatusEnum::class,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logUnguarded()
            ->logOnlyDirty();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PaymentVoucherLine::class);
    }

    public function reconciliationMatch(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(\Modules\Finance\Banking\Models\ReconciliationMatch::class, 'matchable');
    }

    protected static function newFactory()
    {
        return \Modules\Finance\Payables\database\Factories\PaymentVoucherFactory::new();
    }
}
