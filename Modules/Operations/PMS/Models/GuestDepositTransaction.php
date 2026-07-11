<?php

namespace Modules\Operations\PMS\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentTenderTypeEnum;
use Shared\Traits\HasUlid;

class GuestDepositTransaction extends Model
{
    use HasUlid;

    protected $fillable = [];
    protected $guarded = ['*'];
    protected $casts = [
        'amount' => 'decimal:2',
        'tender_type' => GuestPaymentTenderTypeEnum::class,
        'lifecycle_status' => GuestDepositLifecycleStatusEnum::class,
        'recorded_at' => 'datetime',
        'source_snapshot' => 'array',
    ];

    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function guest(): BelongsTo { return $this->belongsTo(Guest::class); }
    public function cashierSession(): BelongsTo { return $this->belongsTo(CashierSession::class); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
    public function applications(): HasMany { return $this->hasMany(GuestDepositApplication::class); }
    public function reversals(): HasMany { return $this->hasMany(GuestDepositReversal::class); }
    public function refunds(): HasMany { return $this->hasMany(GuestRefundTransaction::class); }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new DomainException('Guest deposits are immutable and cannot be deleted.'));
    }
}
