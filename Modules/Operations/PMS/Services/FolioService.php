<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Validation\ValidationException;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Events\FolioItemVoided;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Repositories\FolioItemRepository;
use Modules\Operations\PMS\Repositories\FolioRepository;

/**
 * PMS Folio Service — compatibility façade over GuestLedgerFolioAggregateService.
 *
 * GLF-A: This service is now a narrow wrapper. Aggregate opening and item
 * posting are delegated to GuestLedgerFolioAggregateService. This class
 * retains legacy close/void operations only — those remain unexpanded
 * and must NOT be treated as settlement or checkout evidence.
 */
class FolioService
{
    public function __construct(
        private FolioRepository                  $folioRepository,
        private FolioItemRepository              $folioItemRepository,
        private GuestLedgerFolioAggregateService $aggregate,
    ) {}

    public function find(string $id): Folio
    {
        return $this->folioRepository->find($id);
    }

    /**
     * Open a new folio for a reservation.
     *
     * GLF-A: This is now a narrow compatibility wrapper. Property, guest,
     * currency, status, totals, and window number are resolved server-side
     * by GuestLedgerFolioAggregateService. Caller-supplied $data is
     * restricted to folio_number and currency overrides for backward
     * compatibility only — aggregate-owned fields are IGNORED.
     *
     * @deprecated Prefer GuestLedgerFolioAggregateService::openWindow() for
     *             new callers. This wrapper remains for legacy compatibility.
     */
    public function createForReservation(string $reservationId, array $data = []): Folio
    {
        // $data is intentionally ignored — all aggregate-owned fields are
        // resolved server-side by GuestLedgerFolioAggregateService.
        unset($data);

        $actor = auth()->user();

        if (! $actor) {
            throw new \RuntimeException('Authenticated actor required to open a folio.');
        }

        // Use a deterministic idempotency key scoped to the reservation.
        // This ensures replay-safety for legacy callers while routing
        // through the controlled aggregate service.
        $idempotencyKey = 'createForReservation-' . $reservationId;

        return $this->aggregate->openWindow($actor, $reservationId, $idempotencyKey);
    }

    /**
     * Post a charge or payment line item to a folio.
     *
     * GLF-A: Delegates to GuestLedgerFolioAggregateService::postItem().
     * Server-owned fields (property_id, folio_id, is_void, posted_at,
     * posted_by) are resolved server-side and ignored if passed in $data.
     *
     * Positive amounts = charges; negative amounts = credits.
     * Negative amounts are legacy cached categories — they are NOT
     * authoritative payment-allocation evidence (future GLF-B).
     */
    public function postItem(string $folioId, array $data): FolioItem
    {
        $actor = auth()->user();

        if (! $actor) {
            throw new \RuntimeException('Authenticated actor required to post a folio item.');
        }

        return $this->aggregate->postItem($actor, $folioId, $data);
    }

    /**
     * Void a folio line item (marks is_void = true; line items are immutable).
     * Totals are recalculated after the void.
     */
    public function voidItem(string $itemId): FolioItem
    {
        $item = $this->folioItemRepository->findOrFail($itemId);

        if ($item->is_void) {
            throw ValidationException::withMessages([
                'item' => ['This folio item has already been voided.'],
            ]);
        }

        $item = $this->folioItemRepository->voidItem($itemId);

        $this->recalculateTotals($item->folio_id);

        event(new FolioItemVoided($item));

        return $item;
    }

    /**
     * Recompute total_charges, total_payments, and balance from active
     * (non-void) items.
     *
     * GLF-A: Uses row locking and bcmath for decimal-safe recalculation.
     * Results are cached operational projections — NOT settlement evidence.
     */
    public function recalculateTotals(string $folioId): Folio
    {
        $folio = $this->folioRepository->findOrFail($folioId);

        $this->aggregate->recalculateTotalsLocked($folio);

        return $folio->fresh();
    }

    /**
     * Close a folio.
     *
     * GLF-A: LEGACY ONLY. This method exists for terminal lifecycle
     * management. It does NOT represent settlement, checkout, or
     * financial close. Future GLF-D will replace this with controlled
     * settlement-aware folio closure.
     */
    public function close(string $folioId): Folio
    {
        $folio = $this->folioRepository->findOrFail($folioId);

        if (! $folio->status->canTransitionTo(FolioStatusEnum::Closed)) {
            throw ValidationException::withMessages([
                'folio' => ["Cannot close a folio in {$folio->status->label()} status."],
            ]);
        }

        $folio->update(['status' => FolioStatusEnum::Closed]);

        return $folio->fresh();
    }

    /**
     * Void a folio.
     *
     * GLF-A: LEGACY ONLY. This method exists for terminal lifecycle
     * management. It does NOT represent settlement, checkout, or
     * financial void.
     */
    public function void(string $folioId): Folio
    {
        $folio = $this->folioRepository->findOrFail($folioId);

        if (! $folio->status->canTransitionTo(FolioStatusEnum::Void)) {
            throw ValidationException::withMessages([
                'folio' => ["Cannot void a folio in {$folio->status->label()} status."],
            ]);
        }

        $folio->update(['status' => FolioStatusEnum::Void]);

        return $folio->fresh();
    }
}
