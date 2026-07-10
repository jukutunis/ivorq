<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Events\FolioCreated;
use Modules\Operations\PMS\Events\FolioItemPosted;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Repositories\FolioItemRepository;
use Modules\Operations\PMS\Repositories\FolioRepository;
use Shared\Services\CurrentPropertyService;

/**
 * PMS Guest Ledger — Folio Aggregate Service.
 *
 * Owns folio opening, item posting, and cached-total recalculation within
 * the PMS Guest Ledger boundary (ADR-088).
 *
 * GLF-A establishes aggregate identity and integrity only. It does NOT
 * implement guest payment allocation, deposit, refund, AR transfer,
 * settlement readiness, folio closure, or checkout.
 */
class GuestLedgerFolioAggregateService
{
    private const IDEMPOTENCY_KEY_MAX_LENGTH = 64;

    public function __construct(
        private FolioRepository        $folioRepository,
        private FolioItemRepository    $folioItemRepository,
        private CurrentPropertyService $currentProperty,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // Folio Opening — Interactive Path
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Open a new folio window for a reservation.
     *
     * All aggregate-owned fields are resolved server-side. Idempotent:
     * the same (property, idempotencyKey) returns the existing folio.
     *
     * Lock order (consistent across all paths):
     *   1. Property row
     *   2. Reservation row
     *   3. Idempotency lookup
     *   4. Window allocation
     *   5. Folio-number allocation
     *   6. Creation
     */
    public function openWindow(
        User $actor,
        string $reservationId,
        string $idempotencyKey
    ): Folio {
        // 1. Resolve current property
        $propertyId = $this->currentProperty->resolveOrFail();

        // 2. Guard actor — active membership required
        $this->guardActiveActorProperty($actor, $propertyId);

        // 3. Validate and normalise idempotency key
        $idempotencyKey = $this->validateIdempotencyKey($idempotencyKey);

        // 4. Resolve reservation by ID + property
        $reservation = $this->resolveReservation($reservationId, $propertyId);

        // 5. Resolve primary guest
        $guestId = $reservation->primary_guest_id;
        if (empty($guestId)) {
            throw ValidationException::withMessages([
                'reservation' => ['Reservation has no primary guest.'],
            ]);
        }

        // 6. Resolve currency from Property (immutable — ADR-001)
        $currency = Property::findOrFail($propertyId)->currency;

        // 7. Transaction with consistent lock order
        return DB::transaction(function () use (
            $propertyId, $reservation, $guestId, $currency, $idempotencyKey
        ) {
            // Lock Property row (serialises folio-number generation)
            Property::where('id', $propertyId)->lockForUpdate()->firstOrFail();

            // Lock Reservation row
            Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            // Idempotency check
            $existing = Folio::withoutGlobalScope('property')
                ->where('property_id', $propertyId)
                ->where('opening_idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                $this->guardIdempotencyReplay($existing, $reservation->id);
                return $existing;
            }

            return $this->createFolioLocked(
                $propertyId, $reservation->id, $guestId, $currency, $idempotencyKey
            );
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Folio Opening — System Path (check-in listener)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * System-driven folio opening.
     *
     * The aggregate service independently resolves property, guest, and
     * currency from authoritative database sources. The caller must NOT
     * supply property ID, guest ID, currency, totals, status, or window
     * number — even a stale or inconsistent event object cannot override
     * the authoritative sources.
     *
     * @param string $reservationId Reservation ULID.
     * @param string $sourcePurpose Short label identifying the system
     *                              caller (e.g. 'check-in-listener').
     */
    public function openWindowSystem(
        string $reservationId,
        string $sourcePurpose
    ): Folio {
        // 1. Resolve reservation (BelongsToProperty scope limits to current property)
        $reservation = Reservation::findOrFail($reservationId);
        $propertyId  = $reservation->property_id;

        // 2. Resolve the Property for currency
        $property = Property::findOrFail($propertyId);

        // 3. Resolve primary guest from Reservation
        $guestId = $reservation->primary_guest_id;
        if (empty($guestId)) {
            throw ValidationException::withMessages([
                'reservation' => ['Reservation has no primary guest.'],
            ]);
        }

        // 4. Build deterministic source-proven idempotency key
        $idempotencyKey = $this->validateIdempotencyKey(
            'system-' . $sourcePurpose . '-' . $reservationId
        );

        // 5. Transaction with consistent lock order
        return DB::transaction(function () use (
            $propertyId, $reservation, $guestId, $property, $idempotencyKey
        ) {
            // Lock Property row
            Property::where('id', $propertyId)->lockForUpdate()->firstOrFail();

            // Lock Reservation row
            Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            // Idempotency check
            $existing = Folio::withoutGlobalScope('property')
                ->where('property_id', $propertyId)
                ->where('opening_idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                $this->guardIdempotencyReplay($existing, $reservation->id);
                return $existing;
            }

            return $this->createFolioLocked(
                $propertyId,
                $reservation->id,
                $guestId,
                $property->currency,
                $idempotencyKey
            );
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // FolioItem Posting
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Post a line item to an open folio.
     *
     * Business input only: item_type, description, quantity, amount.
     * All server-owned fields are resolved server-side.
     */
    public function postItem(User $actor, string $folioId, array $data): FolioItem
    {
        $propertyId = $this->currentProperty->resolveOrFail();

        // 1. Guard actor — active membership required
        $this->guardActiveActorProperty($actor, $propertyId);

        // 2. Validate business input (decimal-safe)
        $itemType  = $this->resolveItemType($data['item_type'] ?? null);
        $amount    = $this->normaliseDecimal($data['amount'] ?? null, 'amount');
        $quantity  = $this->normaliseDecimal($data['quantity'] ?? '1', 'quantity');
        $desc      = (string) ($data['description'] ?? '');

        // 3. Transaction with lock + revalidation
        return DB::transaction(function () use (
            $actor, $folioId, $propertyId, $itemType, $amount, $quantity, $desc
        ) {
            // Lock and re-resolve Folio
            $folio = $this->folioRepository->lockForUpdate($folioId, $propertyId);

            // Re-validate OPEN status post-lock
            if ($folio->status !== FolioStatusEnum::Open) {
                throw ValidationException::withMessages([
                    'folio' => ['Items can only be posted to an open folio.'],
                ]);
            }

            // Create item with server-resolved fields only
            $item = $this->folioItemRepository->createControlled(
                [
                    'item_type'   => $itemType,
                    'description' => $desc,
                    'quantity'    => $quantity,
                    'amount'      => $amount,
                ],
                [
                    'property_id' => $folio->property_id,
                    'folio_id'    => $folio->id,
                    'is_void'     => false,
                    'posted_at'   => now(),
                    'posted_by'   => $actor->id,
                    'created_by'  => $actor->id,
                ]
            );

            // Recalculate totals under same lock
            $this->recalculateAndPersistLocked($folio);

            event(new FolioItemPosted($item));

            return $item->fresh();
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Canonical Totals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Public recalculate — opens its own transaction and lock.
     *
     * Safe for external callers that do not already hold a folio lock.
     */
    public function recalculateTotals(string $folioId, string $propertyId): Folio
    {
        return DB::transaction(function () use ($folioId, $propertyId) {
            $folio = $this->folioRepository->lockForUpdate($folioId, $propertyId);
            $this->recalculateAndPersistLocked($folio);

            return $folio->fresh();
        });
    }

    /**
     * Locked recalculation — caller MUST hold a folio row lock.
     *
     * Private: only postItem() and recalculateTotals() may invoke this.
     */
    private function recalculateAndPersistLocked(Folio $folio): void
    {
        $items = $this->folioItemRepository->forFolio($folio->id, includeVoided: false);

        $totalCharges  = '0';
        $totalPayments = '0';

        foreach ($items as $item) {
            $amt = $item->amount;

            if (bccomp((string) $amt, '0', 2) > 0) {
                $totalCharges = bcadd($totalCharges, (string) $amt, 2);
            } elseif (bccomp((string) $amt, '0', 2) < 0) {
                // Legacy credit — NOT authoritative payment-allocation evidence
                $negAbs = bcsub('0', (string) $amt, 2);
                $totalPayments = bcadd($totalPayments, $negAbs, 2);
            }
        }

        $balance = bcsub($totalCharges, $totalPayments, 2);

        $folio->forceFill([
            'total_charges'  => $totalCharges,
            'total_payments' => $totalPayments,
            'balance'        => $balance,
        ])->save();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internal: creation (caller holds Property + Reservation locks)
    // ─────────────────────────────────────────────────────────────────────

    private function createFolioLocked(
        string $propertyId,
        string $reservationId,
        string $guestId,
        string $currency,
        string $idempotencyKey
    ): Folio {
        $nextWindow  = $this->allocateNextWindowNumber($propertyId, $reservationId);
        $folioNumber = $this->generateFolioNumberLocked($propertyId);

        $folio = $this->folioRepository->createControlled([
            'property_id'              => $propertyId,
            'folio_number'             => $folioNumber,
            'reservation_id'           => $reservationId,
            'guest_id'                 => $guestId,
            'status'                   => FolioStatusEnum::Open,
            'currency'                 => $currency,
            'window_number'            => $nextWindow,
            'opening_idempotency_key'  => $idempotencyKey,
            'total_charges'            => '0.00',
            'total_payments'           => '0.00',
            'balance'                  => '0.00',
        ]);

        event(new FolioCreated($folio));

        return $folio->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internal: helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Actor must have active membership in the current property.
     */
    private function guardActiveActorProperty(User $actor, string $propertyId): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $membership = $actor->properties()
            ->where('property_id', $propertyId)
            ->wherePivot('status', 'active')
            ->first();

        if (! $membership) {
            throw ValidationException::withMessages([
                'actor' => ['Actor does not have active membership in the current property.'],
            ]);
        }
    }

    /**
     * Validate and normalise the idempotency key.
     */
    private function validateIdempotencyKey(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency key must not be empty.'],
            ]);
        }

        if (mb_strlen($key) > self::IDEMPOTENCY_KEY_MAX_LENGTH) {
            throw ValidationException::withMessages([
                'idempotency_key' => [
                    'Idempotency key must not exceed ' . self::IDEMPOTENCY_KEY_MAX_LENGTH . ' characters.',
                ],
            ]);
        }

        return $key;
    }

    /**
     * On existing idempotency key: verify it belongs to the same reservation.
     * A key reused for a different reservation is a controlled conflict.
     */
    private function guardIdempotencyReplay(Folio $existing, string $requestedReservationId): void
    {
        if ($existing->reservation_id !== $requestedReservationId) {
            throw ValidationException::withMessages([
                'idempotency_key' => [
                    'IDEMPOTENCY_KEY_REUSE_CONFLICT: This idempotency key is already used for a different reservation.',
                ],
            ]);
        }
        // True replay — same property, same reservation, same key → same folio
    }

    private function resolveReservation(string $reservationId, string $propertyId): Reservation
    {
        $reservation = Reservation::withoutGlobalScope('property')
            ->where('id', $reservationId)
            ->where('property_id', $propertyId)
            ->first();

        if (! $reservation) {
            throw ValidationException::withMessages([
                'reservation' => ['Reservation not found in the current property.'],
            ]);
        }

        return $reservation;
    }

    private function resolveItemType(mixed $itemType): FolioItemTypeEnum
    {
        if ($itemType instanceof FolioItemTypeEnum) {
            return $itemType;
        }

        if (is_string($itemType)) {
            $resolved = FolioItemTypeEnum::tryFrom($itemType);
            if (! $resolved) {
                throw ValidationException::withMessages([
                    'item_type' => ['Invalid folio item type: ' . $itemType],
                ]);
            }
            return $resolved;
        }

        throw ValidationException::withMessages([
            'item_type' => ['A valid folio item type is required.'],
        ]);
    }

    /**
     * Normalise a decimal value to a scale-2 string using bcmath.
     * Rejects non-numeric, sub-cent values that would silently truncate,
     * zero amounts, and values exceeding database precision.
     */
    private function normaliseDecimal(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                $field => [ucfirst($field) . ' is required.'],
            ]);
        }

        // Reject scientific notation and non-numeric strings
        if (is_string($value) && preg_match('/[eE]/', $value)) {
            throw ValidationException::withMessages([
                $field => [ucfirst($field) . ' must be a plain decimal number.'],
            ]);
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                $field => [ucfirst($field) . ' must be a valid number.'],
            ]);
        }

        // Normalise to scale-2 string
        $normalised = bcadd((string) $value, '0', 2);

        // Reject sub-cent after normalisation (e.g. "0.001" truncates to "0.00")
        if (bccomp($normalised, '0.00', 2) === 0 && bccomp((string) $value, '0', 3) !== 0) {
            throw ValidationException::withMessages([
                $field => [ucfirst($field) . ' resolves to zero at scale 2. Provide a value with at most 2 decimal places.'],
            ]);
        }

        // Reject zero after normalisation
        if (bccomp($normalised, '0.00', 2) === 0) {
            throw ValidationException::withMessages([
                $field => [ucfirst($field) . ' must be non-zero.'],
            ]);
        }

        // Reject negative quantity
        if ($field === 'quantity' && bccomp($normalised, '0.00', 2) < 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be a positive number.'],
            ]);
        }

        // Reject overflow: database decimal(12,2) = max 10 integer digits
        $maxValue = '9999999999.99';
        $absValue = $normalised;
        if (bccomp($absValue, '0', 2) < 0) {
            $absValue = bcsub('0', $absValue, 2);
        }
        if (bccomp($absValue, $maxValue, 2) > 0) {
            throw ValidationException::withMessages([
                $field => [ucfirst($field) . ' exceeds maximum supported precision.'],
            ]);
        }

        return $normalised;
    }

    /**
     * Allocate the next window number for a reservation.
     * Caller MUST hold reservation lock.
     */
    private function allocateNextWindowNumber(string $propertyId, string $reservationId): int
    {
        $maxWindow = Folio::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)
            ->max('window_number');

        return ($maxWindow ?? 0) + 1;
    }

    /**
     * Generate a folio number. Caller MUST hold the Property lock.
     *
     * Property-wide sequential: FOL-XXXXX.
     * The Property row lock serialises this across all reservations.
     */
    private function generateFolioNumberLocked(string $propertyId): string
    {
        $seq = Folio::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->withTrashed()
            ->count() + 1;

        return sprintf('FOL-%05d', $seq);
    }
}
