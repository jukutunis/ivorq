<?php

namespace Modules\Operations\FrontDesk\Services;

use DateTimeInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
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
     * Resolve authoritative PostgreSQL wall-clock time after row-lock acquisition.
     *
     * Uses clock_timestamp() which returns the actual current wall-clock time,
     * not the transaction-start time (CURRENT_TIMESTAMP / now() / transaction_timestamp()).
     * This is essential when a transaction may have waited on a FOR UPDATE row lock.
     *
     * Returns a Carbon instance normalized to UTC for consistent storage
     * in timestamp-without-time-zone columns.
     *
     * Must be called AFTER lockForUpdate() inside the transaction.
     */
    private function resolveDatabaseWallClock(): Carbon
    {
        $result = DB::selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' AS wall_clock");

        return Carbon::parse($result->wall_clock);
    }

    /**
     * Convert a PostgreSQL trigger QueryException into a DomainException
     * when it carries a known FD-C2 marker, preserving the trigger's
     * defense-in-depth without exposing raw database errors.
     */
    private function throwTriggerDomainException(QueryException $e): void
    {
        $message = $e->getMessage();

        if (str_contains($message, 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM')) {
            throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM', 0, $e);
        }
        if (str_contains($message, 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION')) {
            throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION', 0, $e);
        }
        if (str_contains($message, 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE')) {
            throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE', 0, $e);
        }
        if (str_contains($message, 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_DELETE_FORBIDDEN')) {
            throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_DELETE_FORBIDDEN', 0, $e);
        }

        throw $e;
    }

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

        $currentPropertyId = $this->currentProperty->resolveOrFail();

        if ($propertyId !== $currentPropertyId) {
            throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
        }

        return DB::transaction(function () use ($currentPropertyId, $handoffId, $leaseSeconds): array {
            $handoff = FrontDeskCheckoutHousekeepingHandoff::query()
                ->forProperty($currentPropertyId)
                ->where('id', $handoffId)
                ->lockForUpdate()
                ->first();

            if (! $handoff) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            // Resolve authoritative wall-clock time AFTER the row lock
            $dbNow = $this->resolveDatabaseWallClock();

            $status = $handoff->delivery_status;

            // DELIVERED is terminal — cannot be claimed
            if ($status === FrontDeskCheckoutHousekeepingHandoffStatusEnum::Delivered) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            // Active unexpired claim — cannot be stolen.
            // Use database-side comparison for full microsecond precision.
            $activeClaim = DB::selectOne(
                "SELECT 1 FROM front_desk_checkout_housekeeping_handoffs WHERE id = ? AND delivery_status = 'CLAIMED' AND claim_expires_at > (clock_timestamp() AT TIME ZONE 'UTC')",
                [$handoffId]
            );
            if ($activeClaim !== null) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            // Not yet available.
            // Use database-side comparison for full microsecond precision.
            $notAvailable = DB::selectOne(
                "SELECT 1 FROM front_desk_checkout_housekeeping_handoffs WHERE id = ? AND available_at > (clock_timestamp() AT TIME ZONE 'UTC')",
                [$handoffId]
            );
            if ($notAvailable !== null) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            // Generate claim token
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $claimExpiresAt = $dbNow->copy()->addSeconds($leaseSeconds);

            $handoff->delivery_status = FrontDeskCheckoutHousekeepingHandoffStatusEnum::Claimed;
            $handoff->attempts = $handoff->attempts + 1;
            $handoff->claimed_at = $dbNow;
            $handoff->claim_expires_at = $claimExpiresAt;
            $handoff->claim_token_hash = $tokenHash;
            $handoff->delivered_at = null;
            $handoff->failed_at = null;
            $handoff->last_error_code = null;
            try {
                $handoff->save();
            } catch (QueryException $e) {
                $this->throwTriggerDomainException($e);
            }

            // Refresh from database to get trigger-owned timestamps
            $handoff->refresh();

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
        $currentPropertyId = $this->currentProperty->resolveOrFail();

        if ($propertyId !== $currentPropertyId) {
            throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
        }

        return DB::transaction(function () use ($currentPropertyId, $handoffId, $claimToken): FrontDeskCheckoutHousekeepingHandoff {
            $handoff = FrontDeskCheckoutHousekeepingHandoff::query()
                ->forProperty($currentPropertyId)
                ->where('id', $handoffId)
                ->lockForUpdate()
                ->first();

            if (! $handoff) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            // Resolve authoritative wall-clock time AFTER the row lock
            $dbNow = $this->resolveDatabaseWallClock();

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

            // Claim must not be expired according to database wall clock.
            // Use database-side comparison for full microsecond precision.
            $expiredCheck = DB::selectOne(
                "SELECT 1 FROM front_desk_checkout_housekeeping_handoffs WHERE id = ? AND delivery_status = 'CLAIMED' AND claim_expires_at <= (clock_timestamp() AT TIME ZONE 'UTC')",
                [$handoffId]
            );
            if ($expiredCheck !== null) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM');
            }

            $handoff->delivery_status = FrontDeskCheckoutHousekeepingHandoffStatusEnum::Delivered;
            $handoff->delivered_at = $dbNow;
            try {
                $handoff->save();
            } catch (QueryException $e) {
                $this->throwTriggerDomainException($e);
            }

            // Return refreshed model with trigger-owned delivered_at
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
        // Validate error code syntax first (before property resolution —
        // the error code pattern is a public format check, not a disclosure)
        if (! preg_match('/^[A-Z0-9_]{1,100}$/', $errorCode)) {
            throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_ERROR_CODE');
        }

        $currentPropertyId = $this->currentProperty->resolveOrFail();

        if ($propertyId !== $currentPropertyId) {
            throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
        }

        return DB::transaction(function () use ($currentPropertyId, $handoffId, $claimToken, $errorCode, $retryAt): FrontDeskCheckoutHousekeepingHandoff {
            $handoff = FrontDeskCheckoutHousekeepingHandoff::query()
                ->forProperty($currentPropertyId)
                ->where('id', $handoffId)
                ->lockForUpdate()
                ->first();

            if (! $handoff) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
            }

            // Resolve authoritative wall-clock time AFTER the row lock
            $dbNow = $this->resolveDatabaseWallClock();

            // Already failed — idempotent replay check
            if ($handoff->delivery_status === FrontDeskCheckoutHousekeepingHandoffStatusEnum::Failed) {
                if (! hash_equals(hash('sha256', $claimToken), $handoff->claim_token_hash ?? '')) {
                    throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_CLAIM_TOKEN');
                }
                if ($handoff->last_error_code !== $errorCode) {
                    throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_CONFLICTING_REPLAY');
                }
                // Compare retryAt to persisted available_at at DB precision
                $persistedAvailableAt = $handoff->available_at;
                if ($persistedAvailableAt === null || $persistedAvailableAt->format('Y-m-d H:i:s') !== (new \DateTimeImmutable('@' . $retryAt->getTimestamp()))->format('Y-m-d H:i:s')) {
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

            // Claim must not be expired according to database wall clock.
            $expiredCheck = DB::selectOne(
                "SELECT 1 FROM front_desk_checkout_housekeeping_handoffs WHERE id = ? AND delivery_status = 'CLAIMED' AND claim_expires_at <= (clock_timestamp() AT TIME ZONE 'UTC')",
                [$handoffId]
            );
            if ($expiredCheck !== null) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM');
            }

            // For first FAILED transition, retryAt must be later than database wall clock.
            // Use database-side comparison for full microsecond precision.
            $retryCheck = DB::selectOne(
                "SELECT 1 WHERE ?::timestamptz <= (clock_timestamp() AT TIME ZONE 'UTC')",
                [$retryAt->format('Y-m-d H:i:s.u')]
            );
            if ($retryCheck !== null) {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_RETRY_TIME');
            }

            $handoff->delivery_status = FrontDeskCheckoutHousekeepingHandoffStatusEnum::Failed;
            $handoff->failed_at = $dbNow;
            $handoff->last_error_code = $errorCode;
            $handoff->available_at = $retryAt;
            try {
                $handoff->save();
            } catch (QueryException $e) {
                $this->throwTriggerDomainException($e);
            }

            return $handoff->fresh();
        });
    }
}
