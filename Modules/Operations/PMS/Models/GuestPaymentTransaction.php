<?php

namespace Modules\Operations\PMS\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentTenderTypeEnum;
use Shared\Traits\HasUlid;

class GuestPaymentTransaction extends Model
{
    use HasUlid;

    protected $fillable = [];

    protected $guarded = ['*'];

    protected $casts = [
        'amount' => 'decimal:2',
        'tender_type' => GuestPaymentTenderTypeEnum::class,
        'lifecycle_status' => GuestPaymentLifecycleStatusEnum::class,
        'recorded_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function cashierSession(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(GuestPaymentAllocation::class);
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(GuestPaymentReversal::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (self $model) {
            throw new DomainException(
                'Guest payment transactions are immutable and cannot be deleted.'
            );
        });
    }
}
