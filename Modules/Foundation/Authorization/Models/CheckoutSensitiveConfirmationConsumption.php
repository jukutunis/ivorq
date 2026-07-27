<?php

namespace Modules\Foundation\Authorization\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Traits\HasUlid;

class CheckoutSensitiveConfirmationConsumption extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $table = 'checkout_sensitive_confirmation_consumptions';

    protected $fillable = [
        'issuance_id',
        'confirmation_identity',
        'confirmation_fingerprint',
        'actor_id',
        'company_id',
        'property_id',
        'front_desk_stay_id',
        'checkout_idempotency_key',
        'consumed_at',
        'created_at',
    ];

    protected $casts = [
        'consumed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('P8_CHECKOUT_CONFIRMATION_CONSUMPTION_IMMUTABLE'));
        static::deleting(fn () => throw new DomainException('P8_CHECKOUT_CONFIRMATION_CONSUMPTION_IMMUTABLE'));
    }

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(CheckoutSensitiveConfirmationIssuance::class, 'issuance_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(FrontDeskStay::class, 'front_desk_stay_id');
    }
}
