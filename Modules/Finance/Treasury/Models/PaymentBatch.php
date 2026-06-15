<?php

namespace Modules\Finance\Treasury\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class PaymentBatch extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'payment_batches';
    protected $guarded = ['id'];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    public function vendorPayments(): HasMany
    {
        return $this->hasMany(VendorPayment::class, 'payment_batch_id');
    }
}
