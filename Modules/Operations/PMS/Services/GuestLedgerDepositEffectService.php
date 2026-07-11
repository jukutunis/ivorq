<?php

namespace Modules\Operations\PMS\Services;

use DomainException;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestDepositReversalTypeEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestDepositApplication;
use Modules\Operations\PMS\Models\GuestDepositReversal;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Repositories\FolioItemRepository;

class GuestLedgerDepositEffectService
{
    public const SOURCE_DOMAIN = 'pms_cashiering';
    public const SOURCE_APPLICATION = 'guest_deposit_application';
    public const SOURCE_REVERSAL = 'guest_deposit_application_reversal';

    public function __construct(
        private readonly FolioItemRepository $items,
        private readonly GuestLedgerFolioAggregateService $folios,
    ) {}

    public function applyAcceptedDepositApplication(GuestDepositApplication $application, GuestDepositTransaction $deposit, Folio $folio): FolioItem
    {
        $this->assertMatches($application, $deposit, $folio);
        $existing = $this->sourceItem($application->property_id, self::SOURCE_APPLICATION, $application->id);
        if ($existing) return $existing->fresh();

        $item = $this->items->createControlled([
            'item_type' => FolioItemTypeEnum::Deposit,
            'description' => 'Guest deposit application ' . $deposit->deposit_number,
            'quantity' => '1.00',
            'amount' => bcsub('0.00', $this->amount($application->amount), 2),
        ], [
            'property_id' => $application->property_id, 'folio_id' => $folio->id, 'is_void' => false,
            'posted_at' => $application->applied_at, 'posted_by' => $application->applied_by, 'created_by' => $application->applied_by,
            'source_domain' => self::SOURCE_DOMAIN, 'source_type' => self::SOURCE_APPLICATION, 'source_id' => $application->id,
            'guest_payment_allocation_id' => null, 'guest_payment_reversal_id' => null,
            'guest_deposit_application_id' => $application->id, 'guest_deposit_reversal_id' => null,
            'guest_ar_transfer_decision_id' => null, 'reverses_folio_item_id' => null,
        ]);
        $this->folios->recalculateLocked($folio);
        return $item->fresh();
    }

    public function applyAcceptedDepositApplicationReversal(GuestDepositReversal $reversal, GuestDepositApplication $application, GuestDepositTransaction $deposit, Folio $folio): FolioItem
    {
        if ($reversal->reversal_type !== GuestDepositReversalTypeEnum::ApplicationReversal) throw new DomainException('Only application reversal evidence creates a Folio effect.');
        $this->assertMatches($application, $deposit, $folio);
        $existing = $this->sourceItem($reversal->property_id, self::SOURCE_REVERSAL, $reversal->id);
        if ($existing) return $existing->fresh();
        $original = $this->sourceItem($application->property_id, self::SOURCE_APPLICATION, $application->id);
        if (!$original || $original->folio_id !== $folio->id) throw new DomainException('Original deposit FolioItem is unavailable.');

        $item = $this->items->createControlled([
            'item_type' => FolioItemTypeEnum::DepositReversal,
            'description' => 'Guest deposit application reversal ' . $reversal->reason_code,
            'quantity' => '1.00', 'amount' => $this->amount($reversal->amount),
        ], [
            'property_id' => $reversal->property_id, 'folio_id' => $folio->id, 'is_void' => false,
            'posted_at' => $reversal->reversed_at, 'posted_by' => $reversal->reversed_by, 'created_by' => $reversal->reversed_by,
            'source_domain' => self::SOURCE_DOMAIN, 'source_type' => self::SOURCE_REVERSAL, 'source_id' => $reversal->id,
            'guest_payment_allocation_id' => null, 'guest_payment_reversal_id' => null,
            'guest_deposit_application_id' => $application->id, 'guest_deposit_reversal_id' => $reversal->id,
            'guest_ar_transfer_decision_id' => null, 'reverses_folio_item_id' => $original->id,
        ]);
        $this->folios->recalculateLocked($folio);
        return $item->fresh();
    }

    private function assertMatches(GuestDepositApplication $application, GuestDepositTransaction $deposit, Folio $folio): void
    {
        if ($folio->status !== FolioStatusEnum::Open || $application->property_id !== $deposit->property_id
            || $folio->property_id !== $deposit->property_id || $application->guest_deposit_transaction_id !== $deposit->id
            || $application->folio_id !== $folio->id || $deposit->reservation_id !== $folio->reservation_id
            || $deposit->guest_id !== $folio->guest_id || $deposit->currency !== $folio->currency) {
            throw new DomainException('Deposit application conflicts with Folio source evidence.');
        }
    }
    private function sourceItem(string $propertyId, string $sourceType, string $sourceId): ?FolioItem
    {
        return FolioItem::withoutGlobalScope('property')->where('property_id', $propertyId)->where('source_domain', self::SOURCE_DOMAIN)->where('source_type', $sourceType)->where('source_id', $sourceId)->first();
    }
    private function amount(mixed $value): string { return bcadd((string) $value, '0.00', 2); }
}
