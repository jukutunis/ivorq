<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Validation\ValidationException;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Events\FolioCreated;
use Modules\Operations\PMS\Events\FolioItemPosted;
use Modules\Operations\PMS\Events\FolioItemVoided;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Repositories\FolioItemRepository;
use Modules\Operations\PMS\Repositories\FolioRepository;
use Modules\Operations\PMS\Repositories\ReservationRepository;

class FolioService
{
    public function __construct(
        private FolioRepository       $folioRepository,
        private FolioItemRepository   $folioItemRepository,
        private ReservationRepository $reservationRepository,
    ) {}

    public function find(string $id): Folio
    {
        return $this->folioRepository->find($id);
    }

    /**
     * Open a new folio for a reservation.
     * Pulls property_id and primary_guest_id from the reservation so the caller
     * does not need to pass them.
     */
    public function createForReservation(string $reservationId, array $data = []): Folio
    {
        $reservation = $this->reservationRepository->findOrFail($reservationId);

        $folio = $this->folioRepository->create(array_merge([
            'property_id'    => $reservation->property_id,
            'reservation_id' => $reservation->id,
            'guest_id'       => $reservation->primary_guest_id,
            'status'         => FolioStatusEnum::Open,
            'total_charges'  => 0,
            'total_payments' => 0,
            'balance'        => 0,
        ], $data));

        event(new FolioCreated($folio));

        return $folio;
    }

    /**
     * Post a charge or payment line item to a folio.
     * Positive amounts = charges; negative amounts = credits/payments.
     * Totals are recalculated after each post.
     */
    public function postItem(string $folioId, array $data): FolioItem
    {
        $folio = $this->folioRepository->findOrFail($folioId);

        if ($folio->status !== FolioStatusEnum::Open) {
            throw ValidationException::withMessages([
                'folio' => ['Items can only be posted to an open folio.'],
            ]);
        }

        $item = $this->folioItemRepository->create(array_merge($data, [
            'folio_id'  => $folio->id,
            'is_void'   => false,
            'posted_at' => $data['posted_at'] ?? now(),
            'posted_by' => $data['posted_by'] ?? auth()->id(),
        ]));

        $this->recalculateTotals($folioId);

        event(new FolioItemPosted($item));

        return $item;
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
     * Recompute total_charges, total_payments, and balance from active (non-void) items.
     */
    public function recalculateTotals(string $folioId): Folio
    {
        $folio = $this->folioRepository->findOrFail($folioId);

        $items = $this->folioItemRepository->forFolio($folioId, includeVoided: false);

        $totalCharges  = $items->where('amount', '>', 0)->sum('amount');
        $totalPayments = abs($items->where('amount', '<', 0)->sum('amount'));
        $balance       = $totalCharges - $totalPayments;

        $folio->update([
            'total_charges'  => $totalCharges,
            'total_payments' => $totalPayments,
            'balance'        => $balance,
        ]);

        return $folio->fresh();
    }

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
