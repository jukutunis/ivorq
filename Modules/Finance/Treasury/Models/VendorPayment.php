<?php

namespace Modules\Finance\Treasury\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Treasury\Enums\VendorPaymentStatusEnum;

class VendorPayment extends Model
{
    use HasUlid, BelongsToProperty, SoftDeletes;

    protected $table = 'vendor_payments';
    protected $guarded = ['id'];

    protected $casts = [
        'payment_date' => 'date',
        'total_amount' => 'decimal:2',
        'bank_fee_amount' => 'decimal:2',
        'status' => VendorPaymentStatusEnum::class,
        'approved_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'vendor_payment_id');
    }

    public function markAsApproved(): void
    {
        $this->update([
            'status' => VendorPaymentStatusEnum::Approved,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
    }
}
