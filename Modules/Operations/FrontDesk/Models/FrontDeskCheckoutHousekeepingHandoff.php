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
            $now = now();

            // ── Immutable payload check ──────────────────────────────────
            if ($handoff->isDirty($handoff->getImmutablePayloadColumns())) {
                throw new DomainException(
                    'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE'
                );
            }

            $oldStatus = $original['delivery_status'] ?? null;
            $newStatus = $handoff->delivery_status?->value;

            // ── Same-status checks ──────────────────────────────────────
            if ($oldStatus === $newStatus) {
                if ($oldStatus === 'PENDING' || $oldStatus === 'FAILED') {
                    throw new DomainException(
                        'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION'
                    );
                }
                if ($oldStatus === 'CLAIMED') {
                    // Only allowed when old claim expired.
                    // Time-based expiry is enforced by the database trigger
                    // using clock_timestamp(); do not duplicate here.
                    // attempts must increment
                    if ($handoff->attempts !== ((int) ($original['attempts'] ?? 0)) + 1) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    // claimed_at must change and be >= old claim_expires_at
                    if (! $handoff->isDirty('claimed_at')) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    $oldClaimExpiresAt = $original['claim_expires_at'] ?? null;
                    if ($handoff->claimed_at !== null && $oldClaimExpiresAt !== null
                        && $handoff->claimed_at->format('Y-m-d H:i:s') < $oldClaimExpiresAt) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    // claim_expires_at must be > new claimed_at
                    if ($handoff->claim_expires_at === null || $handoff->claimed_at === null
                        || $handoff->claim_expires_at <= $handoff->claimed_at) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    // token hash must change
                    if ($handoff->claim_token_hash === ($original['claim_token_hash'] ?? null)) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    // available_at must not change
                    if ($handoff->isDirty('available_at')) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    // delivery/failure/error fields must be null
                    if ($handoff->delivered_at !== null || $handoff->failed_at !== null || $handoff->last_error_code !== null) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                }
                if ($oldStatus === 'DELIVERED') {
                    // DELIVERED must not mutate any persisted data
                    if ($handoff->isDirty()) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                }
            }

            // ── Transition-specific checks ──────────────────────────────
            if ($oldStatus !== $newStatus) {
                // PENDING → CLAIMED
                if ($oldStatus === 'PENDING' && $newStatus === 'CLAIMED') {
                    // Time-based eligibility is enforced by the database trigger
                    // using clock_timestamp(); the model must not use stale application time.
                    if ($handoff->attempts !== ((int) ($original['attempts'] ?? 0)) + 1) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->claimed_at === null) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->claim_expires_at === null || $handoff->claimed_at === null
                        || $handoff->claim_expires_at <= $handoff->claimed_at) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->claim_token_hash === null) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->isDirty('available_at')) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->delivered_at !== null || $handoff->failed_at !== null || $handoff->last_error_code !== null) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                }
                // FAILED → CLAIMED
                elseif ($oldStatus === 'FAILED' && $newStatus === 'CLAIMED') {
                    // Time-based retry eligibility is enforced by the database trigger
                    // using clock_timestamp(); the model must not use stale application time.
                    if ($handoff->attempts !== ((int) ($original['attempts'] ?? 0)) + 1) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if (! $handoff->isDirty('claimed_at')) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    $oldAvailableAt = $original['available_at'] ?? null;
                    if ($handoff->claimed_at !== null && $oldAvailableAt !== null
                        && $handoff->claimed_at->format('Y-m-d H:i:s') < $oldAvailableAt) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->claim_expires_at === null || $handoff->claimed_at === null
                        || $handoff->claim_expires_at <= $handoff->claimed_at) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->claim_token_hash === ($original['claim_token_hash'] ?? null)) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->isDirty('available_at')) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->failed_at !== null || $handoff->last_error_code !== null) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->delivered_at !== null) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                }
                // CLAIMED → DELIVERED
                elseif ($oldStatus === 'CLAIMED' && $newStatus === 'DELIVERED') {
                    if ($handoff->isDirty('attempts')) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->isDirty('claimed_at') || $handoff->isDirty('claim_expires_at') || $handoff->isDirty('claim_token_hash')) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->isDirty('available_at')) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->delivered_at === null) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->failed_at !== null || $handoff->last_error_code !== null) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                }
                // CLAIMED → FAILED
                elseif ($oldStatus === 'CLAIMED' && $newStatus === 'FAILED') {
                    if ($handoff->isDirty('attempts')) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->isDirty('claimed_at') || $handoff->isDirty('claim_expires_at') || $handoff->isDirty('claim_token_hash')) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->failed_at === null) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->last_error_code === null) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->available_at === null || $handoff->failed_at === null
                        || $handoff->available_at <= $handoff->failed_at) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                    if ($handoff->delivered_at !== null) {
                        throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
                    }
                }
                // Any other transition is invalid
                else {
                    throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
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
