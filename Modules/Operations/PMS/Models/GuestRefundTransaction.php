<?php

namespace Modules\Operations\PMS\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\PMS\Enums\GuestPaymentTenderTypeEnum;
use Modules\Operations\PMS\Enums\GuestRefundSourceTypeEnum;
use Shared\Traits\HasUlid;

class GuestRefundTransaction extends Model
{
    use HasUlid;
    public $timestamps = false;
    protected $fillable = [];
    protected $guarded = ['*'];
    protected $casts = [
        'amount' => 'decimal:2', 'tender_type' => GuestPaymentTenderTypeEnum::class,
        'refund_source_type' => GuestRefundSourceTypeEnum::class, 'refunded_at' => 'datetime',
        'source_snapshot' => 'array', 'created_at' => 'datetime',
    ];
    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function guest(): BelongsTo { return $this->belongsTo(Guest::class); }
    public function cashierSession(): BelongsTo { return $this->belongsTo(CashierSession::class); }
    public function payment(): BelongsTo { return $this->belongsTo(GuestPaymentTransaction::class, 'guest_payment_transaction_id'); }
    public function deposit(): BelongsTo { return $this->belongsTo(GuestDepositTransaction::class, 'guest_deposit_transaction_id'); }
    public function refundedBy(): BelongsTo { return $this->belongsTo(User::class, 'refunded_by'); }
    protected static function booted(): void { static::deleting(fn () => throw new DomainException('Guest refunds are immutable.')); }
}
