<?php

namespace Modules\Operations\FrontDesk\Services;

use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Operations\FrontDesk\Enums\FrontDeskCheckoutHousekeepingHandoffStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff;
use Shared\Services\CurrentPropertyService;

class FrontDeskCheckoutHousekeepingHandoffDeliveryService
{
    public function __construct(
        private readonly CurrentPropertyService $currentProperty,
    ) {}

    /**
     * Claim an available handoff for delivery.
     *
     * Returns an array with claim details including the raw claim token.
     * The raw token is never persisted — only its SHA-256 hash is stored.
     *
     * @throws DomainException when the handoff is unavailable or the lease is invalid.
     */
    public function claimAvailable(
        string $propertyId,
        string $handoffId,
        int $leaseSeconds = 60
    ): array {
        if ($leaseSeconds < 1 || $leaseSeconds > 300) {
            throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_LEASE');
        }

        $now = now();

        return DB::transaction(function () use ($propertyId, $handoffId, $leaseSeconds, $now): array {
            $handoff = FrontDeskCheckoutHousekeepingHandoff::query()
                ->forProperty($propertyId)
                ->where('id', $handoffId)
                ->lockForUpdate()
                ->first();

            if (! $handoff) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            $status = $handoff->delivery_status;

            // DELIVERED is terminal — cannot be claimed
            if ($status === FrontDeskCheckoutHousekeepingHandoffStatusEnum::Delivered) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            // Active unexpired claim — cannot be stolen
            if (
                $status === FrontDeskCheckoutHousekeepingHandoffStatusEnum::Claimed
                && $handoff->claim_expires_at > $now
            ) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            // Not yet available
            if ($handoff->available_at > $now) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            // Generate claim token
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $claimExpiresAt = $now->copy()->addSeconds($leaseSeconds);

            $handoff->delivery_status = FrontDeskCheckoutHousekeepingHandoffStatusEnum::Claimed;
            $handoff->attempts = $handoff->attempts + 1;
            $handoff->claimed_at = $now;
            $handoff->claim_expires_at = $claimExpiresAt;
            $handoff->claim_token_hash = $tokenHash;
            $handoff->delivered_at = null;
            $handoff->failed_at = null;
            $handoff->last_error_code = null;
            $handoff->save();

            return [
                'handoff_id' => $handoff->id,
                'property_id' => $handoff->property_id,
                'claim_token' => $rawToken,
                'claimed_at' => $handoff->claimed_at->toIso8601String(),
                'claim_expires_at' => $handoff->claim_expires_at->toIso8601String(),
                'attempts' => $handoff->attempts,
                'front_desk_stay_id' => $handoff->front_desk_stay_id,
                'checkout_execution_id' => $handoff->checkout_execution_id,
                'reservation_id' => $handoff->reservation_id,
                'business_date' => $handoff->business_date->toDateString(),
            ];
        });
    }

    /**
     * Mark a claimed handoff as delivered.
     *
     * @throws DomainException when the claim token is invalid or the claim is expired.
     */
    public function markDelivered(
        string $propertyId,
        string $handoffId,
        string $claimToken
    ): FrontDeskCheckoutHousekeepingHandoff {
        $now = now();

        return DB::transaction(function () use ($propertyId, $handoffId, $claimToken, $now): FrontDeskCheckoutHousekeepingHandoff {
            $handoff = FrontDeskCheckoutHousekeepingHandoff::query()
                ->forProperty($propertyId)
                ->where('id', $handoffId)
                ->lockForUpdate()
                ->first();

            if (! $handoff) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            // Already delivered — idempotent replay
            if ($handoff->delivery_status === FrontDeskCheckoutHousekeepingHandoffStatusEnum::Delivered) {
                // Verify claim token matches on replay
                if (! hash_equals(hash('sha256', $claimToken), $handoff->claim_token_hash ?? '')) {
                    throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_CLAIM_TOKEN');
                }
                return $handoff;
            }

            // Must be CLAIMED
            if ($handoff->delivery_status !== FrontDeskCheckoutHousekeepingHandoffStatusEnum::Claimed) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            // Validate claim token
            if (! hash_equals(hash('sha256', $claimToken), $handoff->claim_token_hash ?? '')) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_CLAIM_TOKEN');
            }

            // Claim must not be expired
            if ($handoff->claim_expires_at <= $now) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM');
            }

            $handoff->delivery_status = FrontDeskCheckoutHousekeepingHandoffStatusEnum::Delivered;
            $handoff->delivered_at = $now;
            $handoff->save();

            return $handoff->fresh();
        });
    }

    /**
     * Mark a claimed handoff as failed with a retry time.
     *
     * @throws DomainException when the claim token is invalid, claim is expired,
     *                         error code is invalid, or retry time is not in the future.
     */
    public function markFailed(
        string $propertyId,
        string $handoffId,
        string $claimToken,
        string $errorCode,
        DateTimeInterface $retryAt
    ): FrontDeskCheckoutHousekeepingHandoff {
        $now = now();

        // Validate error code
        if (! preg_match('/^[A-Z0-9_]{1,100}$/', $errorCode)) {
            throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_ERROR_CODE');
        }

        // Validate retry time is in the future
        if ($retryAt <= $now) {
            throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_RETRY_TIME');
        }

        return DB::transaction(function () use ($propertyId, $handoffId, $claimToken, $errorCode, $retryAt, $now): FrontDeskCheckoutHousekeepingHandoff {
            $handoff = FrontDeskCheckoutHousekeepingHandoff::query()
                ->forProperty($propertyId)
                ->where('id', $handoffId)
                ->lockForUpdate()
                ->first();

            if (! $handoff) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            // Already failed — idempotent replay if identical
            if ($handoff->delivery_status === FrontDeskCheckoutHousekeepingHandoffStatusEnum::Failed) {
                if (! hash_equals(hash('sha256', $claimToken), $handoff->claim_token_hash ?? '')) {
                    throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_CLAIM_TOKEN');
                }
                if ($handoff->last_error_code !== $errorCode) {
                    throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_CONFLICTING_REPLAY');
                }
                return $handoff;
            }

            // Must be CLAIMED
            if ($handoff->delivery_status !== FrontDeskCheckoutHousekeepingHandoffStatusEnum::Claimed) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            // Validate claim token
            if (! hash_equals(hash('sha256', $claimToken), $handoff->claim_token_hash ?? '')) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_CLAIM_TOKEN');
            }

            // Claim must not be expired
            if ($handoff->claim_expires_at <= $now) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM');
            }

            $handoff->delivery_status = FrontDeskCheckoutHousekeepingHandoffStatusEnum::Failed;
            $handoff->failed_at = $now;
            $handoff->last_error_code = $errorCode;
            $handoff->available_at = $retryAt;
            $handoff->save();

            return $handoff->fresh();
        });
    }
}
