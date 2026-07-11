<?php

namespace Modules\Operations\PMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Enums\GuestPaymentReversalTypeEnum;
use Shared\Traits\HasUlid;

class GuestPaymentReversal extends Model
{
    use HasUlid;

    public $timestamps = false;

    protected $fillable = [];

    protected $guarded = ['*'];

    protected $casts = [
        'reversal_type' => GuestPaymentReversalTypeEnum::class,
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
        'source_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(GuestPaymentTransaction::class, 'guest_payment_transaction_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(GuestPaymentAllocation::class, 'guest_payment_allocation_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
