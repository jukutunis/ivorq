<?php

namespace Modules\Finance\Payables\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class PaymentVoucherLine extends Model
{
    use HasUlid, HasAuditColumns, SoftDeletes, HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'ap_original_amount' => 'decimal:2',
        'ap_outstanding_before' => 'decimal:2',
        'ap_outstanding_after' => 'decimal:2',
    ];

    public function paymentVoucher(): BelongsTo
    {
        return $this->belongsTo(PaymentVoucher::class);
    }

    public function accountPayable(): BelongsTo
    {
        return $this->belongsTo(AccountPayable::class);
    }
}
