<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Events\FolioItemVoided;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Repositories\FolioItemRepository;
use Modules\Operations\PMS\Repositories\FolioRepository;
use Shared\Services\CurrentPropertyService;

/**
 * PMS Folio Service — compatibility façade over GuestLedgerFolioAggregateService.
 *
 * GLF-A: Aggregate opening and item posting are delegated to
 * GuestLedgerFolioAggregateService. This class retains legacy close/void
 * operations only — those remain unexpanded and must NOT be treated as
 * settlement or checkout evidence.
 */
class FolioService
{
    public function __construct(
        private FolioRepository                  $folioRepository,
        private FolioItemRepository              $folioItemRepository,
        private GuestLedgerFolioAggregateService $aggregate,
        private CurrentPropertyService           $currentProperty,
    ) {}

    public function find(string $id): Folio
    {
        return $this->folioRepository->find($id);
    }

    /**
     * Narrow compatibility wrapper — delegates to the controlled aggregate service.
     *
     * @deprecated Prefer GuestLedgerFolioAggregateService::openWindow().
     */
    public function createForReservation(string $reservationId, array $data = []): Folio
    {
        unset($data);

        $actor = auth()->user();
        if (! $actor) {
            throw new \RuntimeException('Authenticated actor required to open a folio.');
        }

        $idempotencyKey = 'createForReservation-' . $reservationId;

        return $this->aggregate->openWindow($actor, $reservationId, $idempotencyKey);
    }

    /**
     * Narrow compatibility wrapper — delegates to the controlled aggregate service.
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
     * Void a folio line item — transactional with lock.
     *
     * GLF-A: Opens transaction, locks parent Folio, re-resolves item,
     * validates not already voided, marks void, recalculates under lock.
     * This is legacy item void only — NOT payment reversal.
     */
    public function voidItem(string $itemId): FolioItem
    {
        $propertyId = $this->currentProperty->resolveOrFail();

        return DB::transaction(function () use ($itemId, $propertyId) {
            // Lock and re-resolve the item
            $item = $this->folioItemRepository->lockForUpdate($itemId);

            if ($item->is_void) {
                throw ValidationException::withMessages([
                    'item' => ['This folio item has already been voided.'],
                ]);
            }

            // Lock parent Folio (cross-property guarded by item's property_id)
            $folio = $this->folioRepository->lockForUpdate($item->folio_id, $propertyId);

            // Mark void
            $item = $this->folioItemRepository->voidItem($itemId);

            // Recalculate under same parent lock
            $this->aggregate->recalculateTotals($folio->id, $propertyId);

            event(new FolioItemVoided($item));

            return $item;
        });
    }

    /**
     * Recalculate cached totals — opens its own transaction and lock.
     */
    public function recalculateTotals(string $folioId): Folio
    {
        $propertyId = $this->currentProperty->resolveOrFail();

        return $this->aggregate->recalculateTotals($folioId, $propertyId);
    }

    /**
     * Close a folio — LEGACY ONLY.
     */
    public function close(string $folioId): Folio
    {
        $folio = $this->folioRepository->findOrFail($folioId);

        if (! $folio->status->canTransitionTo(FolioStatusEnum::Closed)) {
            throw ValidationException::withMessages([
                'folio' => ["Cannot close a folio in {$folio->status->label()} status."],
            ]);
        }

        $folio->forceFill(['status' => FolioStatusEnum::Closed])->save();

        return $folio->fresh();
    }

    /**
     * Void a folio — LEGACY ONLY.
     */
    public function void(string $folioId): Folio
    {
        $folio = $this->folioRepository->findOrFail($folioId);

        if (! $folio->status->canTransitionTo(FolioStatusEnum::Void)) {
            throw ValidationException::withMessages([
                'folio' => ["Cannot void a folio in {$folio->status->label()} status."],
            ]);
        }

        $folio->forceFill(['status' => FolioStatusEnum::Void])->save();

        return $folio->fresh();
    }
}
