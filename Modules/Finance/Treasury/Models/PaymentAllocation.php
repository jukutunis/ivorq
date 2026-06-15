<?php

namespace Modules\Finance\Treasury\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;
use Modules\Finance\AccountsPayable\Models\ApInvoice;

class PaymentAllocation extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'payment_allocations';
    protected $guarded = ['id'];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
    ];

    public function vendorPayment(): BelongsTo
    {
        return $this->belongsTo(VendorPayment::class, 'vendor_payment_id');
    }

    public function apInvoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class, 'ap_invoice_id');
    }
}
