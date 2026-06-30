<?php

namespace Modules\Finance\Payables\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Finance\Payables\Enums\PaymentProposalStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class PaymentProposal extends Model
{
    use HasUlid, BelongsToProperty, HasAuditColumns;

    protected $fillable = [
        'property_id',
        'vendor_id',
        'proposal_number',
        'currency_code',
        'status',
        'source_fingerprint',
        'total_amount',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'status' => PaymentProposalStatusEnum::class,
        'total_amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentProposalItem::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
