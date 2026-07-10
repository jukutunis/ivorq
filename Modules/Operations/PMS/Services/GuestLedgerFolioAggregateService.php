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
use Modules\Operations\PMS\Events\FolioItemVoided;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Repositories\FolioItemRepository;
use Modules\Operations\PMS\Repositories\FolioRepository;
use Shared\Services\CurrentPropertyService;

/**
 * PMS Guest Ledger — Folio Aggregate Service.
 *
 * Owns folio opening, item posting, item void, and cached-total
 * recalculation within the PMS Guest Ledger boundary (ADR-088).
 *
 * GLF-A establishes aggregate identity and integrity only. It does NOT
 * implement guest payment allocation, deposit, refund, AR transfer,
 * settlement readiness, folio closure, or checkout.
 */
class GuestLedgerFolioAggregateService
{
    private const IDEMPOTENCY_KEY_MAX_LENGTH = 64;

    // Database column precisions
    private const AMOUNT_MAX_INTEGER_DIGITS = 10;  // decimal(12,2)
    private const QUANTITY_MAX_INTEGER_DIGITS = 6; // decimal(8,2)

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
     * The passed $actor MUST match the currently authenticated user.
     * Audit fields (created_by, updated_by) are explicitly bound to
     * the command actor — not to ambient auth state.
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

        // 2. Verify the passed actor matches the authenticated user
        $this->guardActorMatchesAuth($actor);

        // 3. Guard actor — active membership required
        $this->guardActiveActorProperty($actor, $propertyId);

        // 4. Validate and normalise idempotency key
        $idempotencyKey = $this->validateIdempotencyKey($idempotencyKey);

        // 5. Resolve reservation by ID + property
        $reservation = $this->resolveReservation($reservationId, $propertyId);

        // 6. Resolve primary guest
        $guestId = $reservation->primary_guest_id;
        if (empty($guestId)) {
            throw ValidationException::withMessages([
                'reservation' => ['Reservation has no primary guest.'],
            ]);
        }

        // 7. Resolve currency from Property (immutable — ADR-001)
        $currency = Property::findOrFail($propertyId)->currency;

        // 8. Transaction with consistent lock order
        return DB::transaction(function () use (
            $propertyId, $reservation, $guestId, $currency, $idempotencyKey, $actor
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
                $propertyId, $reservation->id, $guestId, $currency, $idempotencyKey, $actor
            );
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Folio Opening — System Path (check-in listener)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * System-driven folio opening.
     *
     * No authenticated actor exists. The aggregate service independently
     * resolves property, guest, and currency from authoritative database
     * sources. created_by remains null — the system does not fabricate a
     * user. The deterministic source purpose is represented by the
     * opening idempotency contract.
     */
    public function openWindowSystem(
        string $reservationId,
        string $sourcePurpose
    ): Folio {
        // 1. Resolve reservation
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
            Property::where('id', $propertyId)->lockForUpdate()->firstOrFail();
            Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            $existing = Folio::withoutGlobalScope('property')
                ->where('property_id', $propertyId)
                ->where('opening_idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                $this->guardIdempotencyReplay($existing, $reservation->id);
                return $existing;
            }

            return $this->createFolioLocked(
                $propertyId, $reservation->id, $guestId, $property->currency, $idempotencyKey, null
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

        $this->guardActorMatchesAuth($actor);
        $this->guardActiveActorProperty($actor, $propertyId);

        // Validate business input (decimal-safe, field-specific precision)
        $itemType  = $this->resolveItemType($data['item_type'] ?? null);
        $amount    = $this->validateAmountDecimal($data['amount'] ?? null);
        $quantity  = $this->validateQuantityDecimal($data['quantity'] ?? '1');
        $desc      = (string) ($data['description'] ?? '');

        return DB::transaction(function () use (
            $actor, $folioId, $propertyId, $itemType, $amount, $quantity, $desc
        ) {
            $folio = $this->folioRepository->lockForUpdate($folioId, $propertyId);

            // Re-validate OPEN status post-lock
            if ($folio->status !== FolioStatusEnum::Open) {
                throw ValidationException::withMessages([
                    'folio' => ['Items can only be posted to an open folio.'],
                ]);
            }

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

            $this->recalculateAndPersistLocked($folio);

            event(new FolioItemPosted($item));

            return $item->fresh();
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Controlled Void
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Void a folio line item through the authorized aggregate boundary.
     *
     * Requires active actor membership. Uses one transaction with
     * consistent lock order (parent Folio → item) and calls the private
     * locked recalculation primitive directly — no nested transaction.
     *
     * This is legacy item void only — NOT payment reversal.
     */
    public function voidItem(User $actor, string $itemId): FolioItem
    {
        $propertyId = $this->currentProperty->resolveOrFail();

        $this->guardActorMatchesAuth($actor);
        $this->guardActiveActorProperty($actor, $propertyId);

        return DB::transaction(function () use ($actor, $itemId, $propertyId) {
            // Lock the target item
            $item = $this->folioItemRepository->lockForUpdate($itemId);

            // Cross-property: item's property must match current property
            if ($item->property_id !== $propertyId) {
                throw ValidationException::withMessages([
                    'item' => ['Folio item not found in the current property.'],
                ]);
            }

            // Lock parent Folio (consistent order: parent before child re-check)
            $folio = $this->folioRepository->lockForUpdate($item->folio_id, $propertyId);

            // Verify item belongs to the locked Folio
            if ($item->folio_id !== $folio->id) {
                throw ValidationException::withMessages([
                    'item' => ['Item does not belong to the resolved folio.'],
                ]);
            }

            // Already voided?
            if ($item->is_void) {
                throw ValidationException::withMessages([
                    'item' => ['This folio item has already been voided.'],
                ]);
            }

            // Mark void via controlled repository method
            $item = $this->folioItemRepository->voidItem($itemId);

            // Use the private locked recalculation — same transaction, same lock
            $this->recalculateAndPersistLocked($folio);

            event(new FolioItemVoided($item));

            return $item;
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Canonical Totals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Public recalculate — opens its own transaction and lock.
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
     * Private: only postItem(), voidItem(), and recalculateTotals() may invoke this.
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
    // Internal: creation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param User|null $actor Command actor (null for system opening).
     */
    private function createFolioLocked(
        string $propertyId,
        string $reservationId,
        string $guestId,
        string $currency,
        string $idempotencyKey,
        ?User $actor
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

        // Audit identity:
        // - Interactive: HasAuditColumns already set created_by/updated_by
        //   from auth()->id(), which matches the command actor (verified by
        //   guardActorMatchesAuth). No further action needed.
        // - System: No authenticated actor should be recorded. If the
        //   ambient auth context set audit fields, null them out explicitly.
        if ($actor === null) {
            if ($folio->created_by !== null) {
                \Illuminate\Support\Facades\DB::table('folios')
                    ->where('id', $folio->id)
                    ->update(['created_by' => null, 'updated_by' => null]);
            }
        }

        event(new FolioCreated($folio));

        return $folio->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internal: guards
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verify the passed User matches the currently authenticated user.
     * Prevents actor-identity swapping at the command boundary.
     */
    private function guardActorMatchesAuth(User $actor): void
    {
        if (! auth()->check()) {
            throw ValidationException::withMessages([
                'actor' => ['An authenticated actor is required for this operation.'],
            ]);
        }

        if (auth()->id() !== $actor->id) {
            throw ValidationException::withMessages([
                'actor' => ['Actor identity does not match the authenticated session.'],
            ]);
        }
    }

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

    private function guardIdempotencyReplay(Folio $existing, string $requestedReservationId): void
    {
        if ($existing->reservation_id !== $requestedReservationId) {
            throw ValidationException::withMessages([
                'idempotency_key' => [
                    'IDEMPOTENCY_KEY_REUSE_CONFLICT: This idempotency key is already used for a different reservation.',
                ],
            ]);
        }
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

    // ─────────────────────────────────────────────────────────────────────
    // Decimal validation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Validate and normalise an amount value.
     *
     * Accepts only canonical plain decimal form:
     *   optional leading minus, digits, optional decimal point, 0-2 fractional digits.
     * Trailing fractional zeros are accepted (1.2300 → 1.23).
     * Excess non-zero fractional digits are rejected (1.239 → reject).
     *
     * Column: decimal(12,2). Max absolute: 9999999999.99. Non-zero required.
     */
    private function validateAmountDecimal(mixed $value): string
    {
        return $this->normaliseDecimal(
            $value, 'amount', self::AMOUNT_MAX_INTEGER_DIGITS, 2, false
        );
    }

    /**
     * Validate and normalise a quantity value.
     *
     * Same canonical form as amount, but strictly positive.
     *
     * Column: decimal(8,2). Max: 999999.99. Non-zero required.
     */
    private function validateQuantityDecimal(mixed $value): string
    {
        return $this->normaliseDecimal(
            $value, 'quantity', self::QUANTITY_MAX_INTEGER_DIGITS, 2, true
        );
    }

    /**
     * Canonical plain-decimal validation and normalisation.
     *
     * @param mixed  $value          Raw input value.
     * @param string $field          Field name for error messages.
     * @param int    $maxIntDigits   Maximum integer digits (excluding sign).
     * @param int    $scale          Database scale (fractional digits).
     * @param bool   $positiveOnly   If true, rejects zero and negative.
     */
    private function normaliseDecimal(
        mixed $value,
        string $field,
        int $maxIntDigits,
        int $scale,
        bool $positiveOnly
    ): string {
        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                $field => [ucfirst($field) . ' is required.'],
            ]);
        }

        // Convert to string for pattern matching
        $str = (string) $value;

        // Reject scientific notation, NaN, INF
        $lower = strtolower($str);
        if (
            str_contains($lower, 'e') ||
            $lower === 'nan' || $lower === '-nan' ||
            $lower === 'inf' || $lower === '-inf' || $lower === '+inf' ||
            $lower === 'infinity' || $lower === '-infinity' || $lower === '+infinity'
        ) {
            throw ValidationException::withMessages([
                $field => [ucfirst($field) . ' must be a plain decimal number.'],
            ]);
        }

        // Validate canonical form: optional minus, digits, optional . and digits
        if (! preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $str)) {
            throw ValidationException::withMessages([
                $field => [ucfirst($field) . ' must be a plain decimal number.'],
            ]);
        }

        $isNegative = str_starts_with($str, '-');
        $absStr = $isNegative ? substr($str, 1) : $str;

        // Split integer and fractional parts
        $parts = explode('.', $absStr);
        $intPart = $parts[0];
        $fracPart = $parts[1] ?? '';

        // Check integer digit count
        if (strlen($intPart) > $maxIntDigits || ($isNegative && bccomp($absStr, '0', $scale) > 0 && strlen($intPart) > $maxIntDigits)) {
            throw ValidationException::withMessages([
                $field => [ucfirst($field) . ' exceeds maximum supported precision.'],
            ]);
        }

        // Check fractional digits
        if (strlen($fracPart) > $scale) {
            // Allow only if excess digits are all zero
            $excess = substr($fracPart, $scale);
            if (rtrim($excess, '0') !== '') {
                throw ValidationException::withMessages([
                    $field => [ucfirst($field) . ' has too many decimal places. Maximum is ' . $scale . '.'],
                ]);
            }
            // Truncate trailing zeros
            $fracPart = substr($fracPart, 0, $scale);
        }

        // Pad fractional part to scale
        $fracPart = str_pad($fracPart, $scale, '0');
        $normalised = ($isNegative ? '-' : '') . $intPart . '.' . $fracPart;

        // bccomp normalisation
        $normalised = bcadd($normalised, '0', $scale);

        // Zero check
        if (bccomp($normalised, '0.00', $scale) === 0) {
            if ($positiveOnly) {
                throw ValidationException::withMessages([
                    $field => [ucfirst($field) . ' must be a positive number.'],
                ]);
            }
            throw ValidationException::withMessages([
                $field => [ucfirst($field) . ' must be non-zero.'],
            ]);
        }

        // Negative check for positive-only fields
        if ($positiveOnly && bccomp($normalised, '0.00', $scale) < 0) {
            throw ValidationException::withMessages([
                $field => [ucfirst($field) . ' must be a positive number.'],
            ]);
        }

        // Overflow check: integer part max digits + fractional part max scale
        $maxAbs = str_repeat('9', $maxIntDigits) . '.' . str_repeat('9', $scale);
        $absNorm = $normalised;
        if (bccomp($absNorm, '0', $scale) < 0) {
            $absNorm = bcsub('0', $absNorm, $scale);
        }
        if (bccomp($absNorm, $maxAbs, $scale) > 0) {
            throw ValidationException::withMessages([
                $field => [ucfirst($field) . ' exceeds maximum supported precision.'],
            ]);
        }

        return $normalised;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internal: window and folio-number allocation
    // ─────────────────────────────────────────────────────────────────────

    private function allocateNextWindowNumber(string $propertyId, string $reservationId): int
    {
        $maxWindow = Folio::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->where('reservation_id', $reservationId)
            ->max('window_number');

        return ($maxWindow ?? 0) + 1;
    }

    private function generateFolioNumberLocked(string $propertyId): string
    {
        $seq = Folio::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->withTrashed()
            ->count() + 1;

        return sprintf('FOL-%05d', $seq);
    }
}
