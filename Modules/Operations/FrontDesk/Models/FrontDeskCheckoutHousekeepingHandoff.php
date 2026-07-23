<?php

namespace Modules\Operations\FrontDesk\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\FrontDesk\Enums\FrontDeskCheckoutHousekeepingHandoffStatusEnum;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class FrontDeskCheckoutHousekeepingHandoff extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'front_desk_checkout_housekeeping_handoffs';

    protected $fillable = [
        'property_id',
        'front_desk_stay_id',
        'reservation_id',
        'checkout_execution_id',
        'property_business_date_id',
        'business_date',
        'idempotency_key',
        'correlation_key',
        'source_hash',
        'occurred_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'delivery_status' => FrontDeskCheckoutHousekeepingHandoffStatusEnum::class,
        'occurred_at' => 'datetime',
        'available_at' => 'datetime',
        'claimed_at' => 'datetime',
        'claim_expires_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'attempts' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $handoff): void {
            $original = $handoff->getRawOriginal();

            if ($handoff->isDirty($handoff->getImmutablePayloadColumns())) {
                throw new DomainException(
                    'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE'
                );
            }

            $oldStatus = $original['delivery_status'] ?? null;
            $newStatus = $handoff->delivery_status?->value;

            if ($oldStatus !== null && $newStatus !== null && $oldStatus !== $newStatus) {
                $allowed = $handoff->getAllowedTransitions()[$oldStatus] ?? [];
                if (! in_array($newStatus, $allowed, true)) {
                    throw new DomainException(
                        'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION'
                    );
                }
            }
        });

        static::deleting(
            fn () => throw new DomainException(
                'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_DELETE_FORBIDDEN'
            )
        );
    }

    /**
     * Columns that must never change after initial insert.
     */
    public function getImmutablePayloadColumns(): array
    {
        return [
            'property_id',
            'front_desk_stay_id',
            'reservation_id',
            'checkout_execution_id',
            'property_business_date_id',
            'business_date',
            'idempotency_key',
            'correlation_key',
            'source_hash',
            'occurred_at',
            'created_at',
        ];
    }

    /**
     * Allowed delivery-status transitions.
     */
    public function getAllowedTransitions(): array
    {
        return [
            'PENDING' => ['CLAIMED'],
            'CLAIMED' => ['CLAIMED', 'DELIVERED', 'FAILED'],
            'FAILED' => ['CLAIMED'],
            'DELIVERED' => ['DELIVERED'],
        ];
    }

    // ── Relations ────────────────────────────────────────────────────────────

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

    public function checkoutExecution(): BelongsTo
    {
        return $this->belongsTo(FrontDeskCheckoutExecution::class, 'checkout_execution_id');
    }

    public function propertyBusinessDate(): BelongsTo
    {
        return $this->belongsTo(PropertyBusinessDate::class, 'property_business_date_id');
    }
}
