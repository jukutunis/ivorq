<?php

namespace Modules\Operations\PMS\Services;

use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Repositories\FolioItemRepository;
use Modules\Operations\PMS\Repositories\FolioRepository;
use Shared\Services\CurrentPropertyService;

/**
 * PMS Folio Service — compatibility façade over GuestLedgerFolioAggregateService.
 *
 * GLF-A controlled open, post, and item void delegate to the aggregate.
 * Legacy whole-folio close and void commands fail closed.
 */
class FolioService
{
    public function __construct(
        private FolioRepository $folioRepository,
        private FolioItemRepository $folioItemRepository,
        private GuestLedgerFolioAggregateService $aggregate,
        private CurrentPropertyService $currentProperty,
        private PmsPropertyAuthority $authority,
    ) {}

    public function find(string $id): Folio
    {
        [, $propertyId] = $this->authority->require('pms.folio.view');

        return Folio::query()->where('property_id', $propertyId)
            ->with(['reservation.primaryGuest', 'guest', 'items.postedBy'])->findOrFail($id);
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

        $idempotencyKey = 'createForReservation-'.$reservationId;

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
        abort(409, 'Controlled folio closure is unavailable through the legacy PMS path.');
    }

    /**
     * Void a folio — LEGACY ONLY.
     */
    public function void(string $folioId): Folio
    {
        abort(409, 'Controlled folio void is unavailable through the legacy PMS path.');
    }
}
