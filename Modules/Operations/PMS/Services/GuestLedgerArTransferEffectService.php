<?php

namespace Modules\Operations\PMS\Services;

use DomainException;
use Modules\Finance\AccountsReceivable\Enums\GuestArTransferDecisionTypeEnum;
use Modules\Finance\AccountsReceivable\Models\GuestArTransferDecision;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestArTransferRequest;
use Modules\Operations\PMS\Repositories\FolioItemRepository;

class GuestLedgerArTransferEffectService
{
    public const SOURCE_DOMAIN = 'accounting_ar';
    public const SOURCE_ACCEPTANCE = 'guest_ar_transfer_acceptance';
    public const SOURCE_REVERSAL = 'guest_ar_transfer_reversal';
    public function __construct(private readonly FolioItemRepository $items, private readonly GuestLedgerFolioAggregateService $folios) {}

    public function applyAcceptedArTransfer(GuestArTransferDecision $decision, GuestArTransferRequest $request, Folio $folio): FolioItem
    {
        $this->assertMatches($decision, $request, $folio, GuestArTransferDecisionTypeEnum::Accepted);
        $existing = $this->sourceItem($decision->property_id, self::SOURCE_ACCEPTANCE, $decision->id);
        if ($existing) return $existing->fresh();
        $item = $this->create($decision, $request, $folio, FolioItemTypeEnum::ArTransfer, self::SOURCE_ACCEPTANCE, bcsub('0.00', $this->amount($request->amount), 2), null);
        $this->folios->recalculateLocked($folio);
        return $item;
    }

    public function applyAcceptedArTransferReversal(GuestArTransferDecision $decision, GuestArTransferRequest $request, Folio $folio): FolioItem
    {
        $this->assertMatches($decision, $request, $folio, GuestArTransferDecisionTypeEnum::Reversed);
        $existing = $this->sourceItem($decision->property_id, self::SOURCE_REVERSAL, $decision->id);
        if ($existing) return $existing->fresh();
        $original = FolioItem::withoutGlobalScope('property')->where('property_id', $decision->property_id)
            ->where('guest_ar_transfer_decision_id', $decision->reverses_decision_id)->where('item_type', FolioItemTypeEnum::ArTransfer->value)->first();
        if (!$original || $original->folio_id !== $folio->id) throw new DomainException('Original AR transfer FolioItem is unavailable.');
        $item = $this->create($decision, $request, $folio, FolioItemTypeEnum::ArTransferReversal, self::SOURCE_REVERSAL, $this->amount($request->amount), $original->id);
        $this->folios->recalculateLocked($folio);
        return $item;
    }

    private function create(GuestArTransferDecision $decision, GuestArTransferRequest $request, Folio $folio, FolioItemTypeEnum $type, string $sourceType, string $amount, ?string $reverses): FolioItem
    {
        return $this->items->createControlled([
            'item_type' => $type, 'description' => 'Guest AR transfer ' . $request->transfer_number,
            'quantity' => '1.00', 'amount' => $amount,
        ], [
            'property_id' => $decision->property_id, 'folio_id' => $folio->id, 'is_void' => false,
            'posted_at' => $decision->decided_at, 'posted_by' => $decision->decided_by, 'created_by' => $decision->decided_by,
            'source_domain' => self::SOURCE_DOMAIN, 'source_type' => $sourceType, 'source_id' => $decision->id,
            'guest_payment_allocation_id' => null, 'guest_payment_reversal_id' => null,
            'guest_deposit_application_id' => null, 'guest_deposit_reversal_id' => null,
            'guest_ar_transfer_decision_id' => $decision->id, 'reverses_folio_item_id' => $reverses,
        ])->fresh();
    }
    private function assertMatches(GuestArTransferDecision $decision, GuestArTransferRequest $request, Folio $folio, GuestArTransferDecisionTypeEnum $type): void
    {
        if ($decision->decision_type !== $type || $decision->property_id !== $request->property_id
            || $decision->guest_ar_transfer_request_id !== $request->id || $folio->property_id !== $request->property_id
            || $folio->id !== $request->folio_id || $folio->status !== FolioStatusEnum::Open || $folio->currency !== $request->currency) {
            throw new DomainException('AR transfer conflicts with Folio source evidence.');
        }
    }
    private function sourceItem(string $propertyId, string $sourceType, string $sourceId): ?FolioItem
    {
        return FolioItem::withoutGlobalScope('property')->where('property_id', $propertyId)->where('source_domain', self::SOURCE_DOMAIN)->where('source_type', $sourceType)->where('source_id', $sourceId)->first();
    }
    private function amount(mixed $value): string { return bcadd((string) $value, '0.00', 2); }
}
