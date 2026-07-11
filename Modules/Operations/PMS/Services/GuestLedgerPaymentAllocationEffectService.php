<?php

namespace Modules\Operations\PMS\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentReversalTypeEnum;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentReversal;
use Modules\Operations\PMS\Repositories\FolioItemRepository;
use Modules\Operations\PMS\Repositories\FolioRepository;

class GuestLedgerPaymentAllocationEffectService
{
    public const SOURCE_DOMAIN = 'pms_cashiering';
    public const SOURCE_ALLOCATION = 'guest_payment_allocation';
    public const SOURCE_ALLOCATION_REVERSAL = 'guest_payment_allocation_reversal';

    public function __construct(
        private readonly FolioRepository $folioRepository,
        private readonly FolioItemRepository $folioItemRepository,
        private readonly GuestLedgerFolioAggregateService $folioAggregate,
    ) {}

    public function applyAcceptedAllocation(string $allocationId): FolioItem
    {
        return DB::transaction(function () use ($allocationId): FolioItem {
            $allocation = GuestPaymentAllocation::with(['payment', 'folio'])
                ->whereKey($allocationId)
                ->lockForUpdate()
                ->firstOrFail();

            $payment = $allocation->payment;
            $folio = $this->folioRepository->lockForUpdate($allocation->folio_id, $allocation->property_id);

            if (!$payment || !$allocation->folio || $folio->status !== FolioStatusEnum::Open) {
                throw new DomainException('Guest payment allocation cannot be applied to the Folio.');
            }

            $this->assertAllocationMatchesFolio($allocation);

            $existing = $this->findSourceItem($allocation->property_id, self::SOURCE_ALLOCATION, $allocation->id);
            if ($existing) {
                return $existing->fresh();
            }

            $amount = $this->negativeAmount($allocation->amount);

            $item = $this->folioItemRepository->createControlled([
                'item_type' => FolioItemTypeEnum::Payment,
                'description' => 'Guest payment allocation ' . $payment->payment_number,
                'quantity' => '1.00',
                'amount' => $amount,
            ], [
                'property_id' => $allocation->property_id,
                'folio_id' => $allocation->folio_id,
                'is_void' => false,
                'posted_at' => $allocation->allocated_at,
                'posted_by' => $allocation->allocated_by,
                'created_by' => $allocation->allocated_by,
                'source_domain' => self::SOURCE_DOMAIN,
                'source_type' => self::SOURCE_ALLOCATION,
                'source_id' => $allocation->id,
                'reverses_folio_item_id' => null,
            ]);

            $this->folioAggregate->recalculateTotals($folio->id, $folio->property_id);

            return $item->fresh();
        });
    }

    public function applyAcceptedReversal(string $reversalId): FolioItem
    {
        return DB::transaction(function () use ($reversalId): FolioItem {
            $reversal = GuestPaymentReversal::with(['payment', 'allocation.folio'])
                ->whereKey($reversalId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reversal->reversal_type !== GuestPaymentReversalTypeEnum::AllocationReversal || !$reversal->allocation) {
                throw new DomainException('Only accepted allocation reversals create FolioItem effects.');
            }

            $allocation = $reversal->allocation;
            $folio = $this->folioRepository->lockForUpdate($allocation->folio_id, $reversal->property_id);
            $this->assertAllocationMatchesFolio($allocation);

            $existing = $this->findSourceItem($reversal->property_id, self::SOURCE_ALLOCATION_REVERSAL, $reversal->id);
            if ($existing) {
                return $existing->fresh();
            }

            $originalItem = $this->findSourceItem($allocation->property_id, self::SOURCE_ALLOCATION, $allocation->id);
            if (!$originalItem) {
                throw new DomainException('Original guest payment allocation FolioItem is unavailable.');
            }

            $item = $this->folioItemRepository->createControlled([
                'item_type' => FolioItemTypeEnum::PaymentReversal,
                'description' => 'Guest payment allocation reversal ' . $reversal->reason_code,
                'quantity' => '1.00',
                'amount' => $this->amountString($reversal->amount),
            ], [
                'property_id' => $reversal->property_id,
                'folio_id' => $folio->id,
                'is_void' => false,
                'posted_at' => $reversal->reversed_at,
                'posted_by' => $reversal->reversed_by,
                'created_by' => $reversal->reversed_by,
                'source_domain' => self::SOURCE_DOMAIN,
                'source_type' => self::SOURCE_ALLOCATION_REVERSAL,
                'source_id' => $reversal->id,
                'reverses_folio_item_id' => $originalItem->id,
            ]);

            $this->folioAggregate->recalculateTotals($folio->id, $folio->property_id);

            return $item->fresh();
        });
    }

    private function assertAllocationMatchesFolio(GuestPaymentAllocation $allocation): void
    {
        $payment = $allocation->payment;
        $folio = $allocation->folio;

        if (
            !$payment ||
            !$folio ||
            $payment->property_id !== $allocation->property_id ||
            $folio->property_id !== $allocation->property_id ||
            $payment->reservation_id !== $folio->reservation_id ||
            $payment->currency !== $folio->currency
        ) {
            throw new DomainException('Guest payment allocation conflicts with Folio source evidence.');
        }
    }

    private function findSourceItem(string $propertyId, string $sourceType, string $sourceId): ?FolioItem
    {
        return FolioItem::withoutGlobalScope('property')
            ->where('property_id', $propertyId)
            ->where('source_domain', self::SOURCE_DOMAIN)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    private function negativeAmount(mixed $amount): string
    {
        return bcsub('0.00', $this->amountString($amount), 2);
    }

    private function amountString(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
