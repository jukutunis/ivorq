<?php

namespace Modules\Finance\Payables\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Payables\Enums\AccountPayableStatusEnum;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;
use Modules\Operations\Purchasing\Models\Vendor;

class AccountPayable extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns, SoftDeletes, HasFactory;

    protected $table = 'accounts_payables';

    protected $fillable = [
        'property_id',
        'vendor_id',
        'vendor_invoice_id',
        'payable_no',
        'invoice_date',
        'due_date',
        'currency_code',
        'exchange_rate',
        'amount',
        'outstanding_amount',
        'status',
        'remarks',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'exchange_rate' => 'decimal:4',
        'amount' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
        'status' => AccountPayableStatusEnum::class,
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function vendorInvoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class);
    }
}
