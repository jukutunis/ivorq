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
    public function __construct(
        private FolioRepository        $folioRepository,
        private FolioItemRepository    $folioItemRepository,
        private CurrentPropertyService $currentProperty,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // Folio Opening
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Open a new folio window for a reservation.
     *
     * All aggregate-owned fields are resolved server-side. The browser
     * must NOT supply property, guest, currency, status, totals, or
     * window number.
     *
     * Idempotent: the same (property, idempotencyKey) returns the
     * existing folio instead of creating a duplicate.
     *
     * @param User  $actor           Authenticated user performing the open.
     * @param string $reservationId  Reservation ULID.
     * @param string $idempotencyKey Caller-supplied idempotency key
     *                               (must be property-unique).
     * @return Folio
     */
    public function openWindow(
        User $actor,
        string $reservationId,
        string $idempotencyKey
    ): Folio {
        // 1. Resolve current property server-side
        $propertyId = $this->currentProperty->resolveOrFail();

        // 2. Confirm actor belongs to and may operate in the current property
        $this->guardActorProperty($actor, $propertyId);

        // 3. Resolve Reservation by identifier + current property
        $reservation = $this->resolveReservation($reservationId, $propertyId);

        // 4. Resolve primary guest from Reservation
        $guestId = $reservation->primary_guest_id;
        if (empty($guestId)) {
            throw ValidationException::withMessages([
                'reservation' => ['Reservation has no primary guest.'],
            ]);
        }

        // 5. Resolve currency from Property base currency (immutable — ADR-001)
        $currency = Property::findOrFail($propertyId)->currency;

        // 6. Begin transaction + lock reservation
        return DB::transaction(function () use (
            $propertyId, $reservation, $guestId, $currency, $idempotencyKey
        ) {
            // Lock the reservation row to prevent concurrent folio creation
            Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            // 7. Resolve existing folio by property + idempotency key
            $existing = Folio::withoutGlobalScope('property')
                ->where('property_id', $propertyId)
                ->where('opening_idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }

            // 8. Allocate next window number
            $nextWindow = $this->allocateNextWindowNumber($propertyId, $reservation->id);

            // 9. Generate folio number (preserve existing convention)
            $folioNumber = $this->generateFolioNumber($propertyId);

            // 10. Create Folio with server-resolved values only
            $folio = $this->folioRepository->create([
                'property_id'              => $propertyId,
                'folio_number'             => $folioNumber,
                'reservation_id'           => $reservation->id,
                'guest_id'                 => $guestId,
                'status'                   => FolioStatusEnum::Open,
                'currency'                 => $currency,
                'window_number'            => $nextWindow,
                'opening_idempotency_key'  => $idempotencyKey,
                'total_charges'            => 0,
                'total_payments'           => 0,
                'balance'                  => 0,
            ]);

            event(new FolioCreated($folio));

            return $folio->fresh();
        });
    }

    /**
     * System-driven folio opening (e.g. check-in listener).
     *
     * Uses a source-proven deterministic idempotency key derived from
     * the reservation identity so that replay is safe.
     *
     * @param string $reservationId
     * @param string $propertyId    Already resolved (listener context).
     * @param string $guestId       Already resolved (stay guest).
     * @param string $currency      Already resolved (property or rate plan).
     * @return Folio
     */
    public function openWindowSystem(
        string $reservationId,
        string $propertyId,
        string $guestId,
        string $currency
    ): Folio {
        // System actor — use a deterministic source-proven key.
        // The actor is the system itself, not a browser user.
        $idempotencyKey = 'system-checkin-' . $reservationId;

        return DB::transaction(function () use (
            $propertyId, $reservationId, $guestId, $currency, $idempotencyKey
        ) {
            // Lock reservation
            Reservation::where('id', $reservationId)->lockForUpdate()->firstOrFail();

            // Check idempotency
            $existing = Folio::withoutGlobalScope('property')
                ->where('property_id', $propertyId)
                ->where('opening_idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }

            // Allocate next window number
            $nextWindow = $this->allocateNextWindowNumber($propertyId, $reservationId);

            // Generate folio number
            $folioNumber = $this->generateFolioNumber($propertyId);

            $folio = $this->folioRepository->create([
                'property_id'              => $propertyId,
                'folio_number'             => $folioNumber,
                'reservation_id'           => $reservationId,
                'guest_id'                 => $guestId,
                'status'                   => FolioStatusEnum::Open,
                'currency'                 => $currency,
                'window_number'            => $nextWindow,
                'opening_idempotency_key'  => $idempotencyKey,
                'total_charges'            => 0,
                'total_payments'           => 0,
                'balance'                  => 0,
            ]);

            event(new FolioCreated($folio));

            return $folio->fresh();
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // FolioItem Posting
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Post a line item to an open folio.
     *
     * Server-owned fields (property_id, folio_id, is_void, posted_at,
     * posted_by, created_by) are resolved server-side and must NOT be
     * accepted from browser input.
     *
     * Only business input (item_type, description, quantity, amount) is
     * accepted from the caller.
     *
     * @param User   $actor   Authenticated user performing the post.
     * @param string $folioId Target folio ULID.
     * @param array  $data    Business input only:
     *                        - item_type (FolioItemTypeEnum)
     *                        - description (string)
     *                        - quantity (positive numeric)
     *                        - amount (non-zero numeric)
     * @return FolioItem
     */
    public function postItem(User $actor, string $folioId, array $data): FolioItem
    {
        $propertyId = $this->currentProperty->resolveOrFail();

        // Resolve Folio server-side, scoped to current property
        $folio = Folio::withoutGlobalScope('property')
            ->where('id', $folioId)
            ->where('property_id', $propertyId)
            ->first();

        if (! $folio) {
            throw ValidationException::withMessages([
                'folio' => ['Folio not found in the current property.'],
            ]);
        }

        // Only OPEN folios may receive items
        if ($folio->status !== FolioStatusEnum::Open) {
            throw ValidationException::withMessages([
                'folio' => ['Items can only be posted to an open folio.'],
            ]);
        }

        // Validate and resolve business input
        $itemType  = $data['item_type'] ?? null;
        $amount    = $data['amount'] ?? null;
        $quantity  = $data['quantity'] ?? 1;

        // Accept both string values and enum instances
        if ($itemType instanceof FolioItemTypeEnum) {
            // already resolved
        } elseif (is_string($itemType)) {
            $itemType = FolioItemTypeEnum::tryFrom($itemType);
            if (! $itemType) {
                throw ValidationException::withMessages([
                    'item_type' => ['Invalid folio item type: ' . $data['item_type']],
                ]);
            }
        } else {
            throw ValidationException::withMessages([
                'item_type' => ['A valid folio item type is required.'],
            ]);
        }

        if (! is_numeric($amount) || (float) $amount == 0) {
            throw ValidationException::withMessages([
                'amount' => ['Amount is required and must be non-zero.'],
            ]);
        }

        if (! is_numeric($quantity) || (float) $quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be a positive number.'],
            ]);
        }

        return DB::transaction(function () use ($folio, $itemType, $amount, $quantity, $data, $actor) {
            // Lock the folio row for totals update
            Folio::where('id', $folio->id)->lockForUpdate()->firstOrFail();

            // Create item with server-resolved fields only.
            // is_void always starts false. posted_at is now.
            // posted_by is the resolved actor.
            $item = $this->folioItemRepository->create([
                'property_id' => $folio->property_id,   // derived from parent Folio
                'folio_id'    => $folio->id,
                'item_type'   => $itemType,
                'description' => $data['description'] ?? '',
                'quantity'    => $quantity,
                'amount'      => $amount,
                'is_void'     => false,
                'posted_at'   => now(),
                'posted_by'   => $actor->id,
            ]);

            // Recalculate cached totals transactionally
            $this->recalculateTotalsLocked($folio);

            event(new FolioItemPosted($item));

            return $item->fresh();
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Canonical Totals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Recalculate cached totals from active (non-void) items.
     *
     * Caller MUST hold a row lock on the folio before invoking.
     * All calculations are property-scoped and decimal-safe.
     *
     * Cached totals are operational projections only — NOT settlement
     * evidence. A zero balance does NOT indicate settlement readiness.
     *
     * @param Folio $folio Locked folio instance.
     * @return void
     */
    public function recalculateTotalsLocked(Folio $folio): void
    {
        $items = $this->folioItemRepository->forFolio($folio->id, includeVoided: false);

        $totalCharges  = '0';
        $totalPayments = '0';

        foreach ($items as $item) {
            $amt = $item->amount;
            if (bccomp((string) $amt, '0', 2) > 0) {
                $totalCharges = bcadd($totalCharges, (string) $amt, 2);
            } elseif (bccomp((string) $amt, '0', 2) < 0) {
                // Legacy credit category — NOT authoritative payment-allocation evidence.
                // Negative amounts are tracked as legacy cached credits only until GLF-B.
                $totalPayments = bcadd($totalPayments, (string) abs($amt), 2);
            }
        }

        $balance = bcsub($totalCharges, $totalPayments, 2);

        $folio->update([
            'total_charges'  => $totalCharges,
            'total_payments' => $totalPayments,
            'balance'        => $balance,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Confirm the actor belongs to the current property.
     */
    private function guardActorProperty(User $actor, string $propertyId): void
    {
        // Super-admins may operate cross-property
        if ($actor->isSuperAdmin()) {
            return;
        }

        // Check default property first
        if ($actor->defaultProperty()?->id === $propertyId) {
            return;
        }

        // Check property memberships (actor may belong to multiple properties)
        $belongsToProperty = $actor->properties()
            ->where('property_id', $propertyId)
            ->exists();

        if (! $belongsToProperty) {
            throw ValidationException::withMessages([
                'actor' => ['Actor does not belong to the current property.'],
            ]);
        }
    }

    /**
     * Resolve a reservation by ID, scoped to the current property.
     */
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

    /**
     * Allocate the next available window number for a reservation.
     *
     * MUST be called inside a transaction with the reservation locked.
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
     * Generate a folio number using the existing per-property sequential
     * convention: FOL-XXXXX.
     */
    private function generateFolioNumber(string $propertyId): string
    {
        $seq = Folio::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->withTrashed()
            ->count() + 1;

        return sprintf('FOL-%05d', $seq);
    }
}
