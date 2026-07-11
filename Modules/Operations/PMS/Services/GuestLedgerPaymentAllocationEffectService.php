<?php

namespace Modules\Operations\PMS\Services;

use DomainException;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentReversalTypeEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentReversal;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Repositories\FolioItemRepository;

class GuestLedgerPaymentAllocationEffectService
{
    public const SOURCE_DOMAIN = 'pms_cashiering';
    public const SOURCE_ALLOCATION = 'guest_payment_allocation';
    public const SOURCE_ALLOCATION_REVERSAL = 'guest_payment_allocation_reversal';

    public function __construct(
        private readonly FolioItemRepository $folioItemRepository,
        private readonly GuestLedgerFolioAggregateService $folioAggregate,
    ) {}

    public function createAllocationItemLocked(
        GuestPaymentAllocation $allocation,
        GuestPaymentTransaction $payment,
        Folio $folio
    ): FolioItem {
        $this->assertAllocationMatchesFolio($allocation, $payment, $folio);

        $existing = $this->findSourceItem($allocation->property_id, self::SOURCE_ALLOCATION, $allocation->id);
        if ($existing) {
            return $existing->fresh();
        }

        $item = $this->folioItemRepository->createControlled([
            'item_type' => FolioItemTypeEnum::Payment,
            'description' => 'Guest payment allocation ' . $payment->payment_number,
            'quantity' => '1.00',
            'amount' => bcsub('0.00', $this->amountString($allocation->amount), 2),
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
            'guest_payment_allocation_id' => $allocation->id,
            'guest_payment_reversal_id' => null,
            'reverses_folio_item_id' => null,
        ]);

        $this->folioAggregate->recalculateLocked($folio);

        return $item->fresh();
    }

    public function createReversalItemLocked(
        GuestPaymentReversal $reversal,
        GuestPaymentAllocation $allocation,
        GuestPaymentTransaction $payment,
        Folio $folio
    ): FolioItem {
        if ($reversal->reversal_type !== GuestPaymentReversalTypeEnum::AllocationReversal) {
            throw new DomainException('Only accepted allocation reversals create FolioItem effects.');
        }

        if (
            $reversal->property_id !== $allocation->property_id ||
            $reversal->property_id !== $payment->property_id ||
            $reversal->guest_payment_transaction_id !== $payment->id ||
            $reversal->guest_payment_allocation_id !== $allocation->id
        ) {
            throw new DomainException('Guest payment reversal conflicts with allocation source evidence.');
        }

        $this->assertAllocationMatchesFolio($allocation, $payment, $folio);

        $existing = $this->findSourceItem($reversal->property_id, self::SOURCE_ALLOCATION_REVERSAL, $reversal->id);
        if ($existing) {
            return $existing->fresh();
        }

        $originalItem = $this->findSourceItem($allocation->property_id, self::SOURCE_ALLOCATION, $allocation->id);
        if (!$originalItem || $originalItem->folio_id !== $folio->id || $originalItem->item_type !== FolioItemTypeEnum::Payment) {
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
            'guest_payment_allocation_id' => $allocation->id,
            'guest_payment_reversal_id' => $reversal->id,
            'reverses_folio_item_id' => $originalItem->id,
        ]);

        $this->folioAggregate->recalculateLocked($folio);

        return $item->fresh();
    }

    private function assertAllocationMatchesFolio(
        GuestPaymentAllocation $allocation,
        GuestPaymentTransaction $payment,
        Folio $folio
    ): void {
        if (
            $folio->status !== FolioStatusEnum::Open ||
            $payment->property_id !== $allocation->property_id ||
            $folio->property_id !== $allocation->property_id ||
            $payment->id !== $allocation->guest_payment_transaction_id ||
            $folio->id !== $allocation->folio_id ||
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

    private function amountString(mixed $amount): string
    {
        $value = (string) $amount;
        if (!preg_match('/^[0-9]+(\.[0-9]+)?$/', $value)) {
            throw new DomainException('Guest payment amount must be a canonical decimal string.');
        }

        [$intPart, $fracPart] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($fracPart) > 2 && rtrim(substr($fracPart, 2), '0') !== '') {
            throw new DomainException('Guest payment amount has too many decimal places.');
        }

        if (strlen($intPart) > 10) {
            throw new DomainException('Guest payment amount exceeds decimal precision.');
        }

        return bcadd($intPart . '.' . str_pad(substr($fracPart, 0, 2), 2, '0'), '0.00', 2);
    }
}
