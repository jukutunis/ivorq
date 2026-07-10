<?php

namespace Modules\Operations\PMS\Services;

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
 * GLF-A: All controlled operations (open, post, void) are delegated to
 * GuestLedgerFolioAggregateService. This class retains legacy close/void
 * operations for the Folio aggregate itself.
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
     * Compatibility wrapper — delegates to the controlled aggregate service.
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
     * Compatibility wrapper — delegates to the controlled aggregate service.
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
     * Void a folio line item — delegates to the authorized aggregate boundary.
     */
    public function voidItem(string $itemId): FolioItem
    {
        $actor = auth()->user();
        if (! $actor) {
            throw new \RuntimeException('Authenticated actor required to void a folio item.');
        }

        return $this->aggregate->voidItem($actor, $itemId);
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
