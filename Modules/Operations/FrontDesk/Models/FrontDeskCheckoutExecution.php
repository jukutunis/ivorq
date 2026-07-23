<?php

namespace Modules\Operations\FrontDesk\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class FrontDeskCheckoutExecution extends Model
{
    use HasUlid, BelongsToProperty;

    public const UPDATED_AT = null;

    protected $table = 'front_desk_checkout_executions';

    protected $fillable = [
        'property_id',
        'front_desk_stay_id',
        'reservation_id',
        'idempotency_key',
        'terminal_stay_status',
        'front_desk_final_review_id',
        'property_business_date_id',
        'business_date',
        'night_audit_source_status',
        'night_audit_source_fingerprint',
        'pms_financial_attestation_status',
        'pms_financial_attestation_fingerprint',
        'general_cashier_attestation_status',
        'general_cashier_attestation_fingerprint',
        'source_hash',
        'occurred_at',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'terminal_stay_status' => FrontDeskStayStatusEnum::class,
        'business_date' => 'date',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(
            fn () => throw new DomainException('FD_C1_CHECKOUT_EXECUTION_EVIDENCE_IMMUTABLE')
        );
        static::deleting(
            fn () => throw new DomainException('FD_C1_CHECKOUT_EXECUTION_EVIDENCE_IMMUTABLE')
        );
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(FrontDeskStay::class, 'front_desk_stay_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function finalReview(): BelongsTo
    {
        return $this->belongsTo(FrontDeskDepartureCheckoutFinalReview::class, 'front_desk_final_review_id');
    }

    public function propertyBusinessDate(): BelongsTo
    {
        return $this->belongsTo(PropertyBusinessDate::class, 'property_business_date_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
