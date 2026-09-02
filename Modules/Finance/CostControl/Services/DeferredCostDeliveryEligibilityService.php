<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Enums\CostDeliveryDispositionClass;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Enums\CostDeliveryProcessingState;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentScopeSnapshot;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Models\CostDeliveryCutover;
use Modules\Finance\CostControl\Models\CostDeliveryCutoverScope;
use Modules\Finance\CostControl\Models\CostDeliveryModeOwnership;
use Modules\Finance\CostControl\Models\CostDeliveryOutboxDisposition;
use Modules\Finance\CostControl\Models\CostDeliveryPilotProperty;
use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
use Modules\Finance\CostControl\ValueObjects\CostLedgerSourceEquivalence;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryEligibleContext;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryEligibleLegContext;
use Modules\Finance\CostControl\ValueObjects\DeferredCostDeliveryFailure;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Outbox\Enums\OutboxStatusEnum;
use Modules\Foundation\Outbox\Models\OutboxMessage;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use RuntimeException;

final class DeferredCostDeliveryEligibilityService
{
    public function __construct(
        private readonly CostLedgerRepository $costLedgerRepository,
    ) {}

    public function evaluate(
        string $outboxMessageId,
    ): DeferredCostDeliveryEligibleContext|DeferredCostDeliveryFailure {
        if (DB::transactionLevel() > 0) {
            return $this->evaluateWithinTransaction($outboxMessageId);
        }

        return DB::transaction(
            fn (): DeferredCostDeliveryEligibleContext|DeferredCostDeliveryFailure => $this->evaluateWithinTransaction($outboxMessageId),
        );
    }

    public function evaluateWithinTransaction(
        string $outboxMessageId,
    ): DeferredCostDeliveryEligibleContext|DeferredCostDeliveryFailure {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(__METHOD__.' requires an active outer transaction.');
        }
        if (trim($outboxMessageId) === '') {
            return $this->failure('OUTBOX_ID_REQUIRED');
        }

        $initialOutbox = OutboxMessage::find($outboxMessageId);
        if ($initialOutbox === null) {
            return $this->failure('OUTBOX_NOT_FOUND');
        }
        $sourceId = $this->validatedOutboxSourceId($initialOutbox, false);
        if ($sourceId instanceof DeferredCostDeliveryFailure) {
            return $sourceId;
        }

        $initialSource = InventoryTransaction::find($sourceId);
        if ($initialSource === null) {
            return $this->failure('INVENTORY_SOURCE_NOT_FOUND');
        }
        $modeFailure = $this->validateDeferredStampShape($initialSource, false);
        if ($modeFailure !== null) {
            return $modeFailure;
        }
        $typeResult = $this->validateSupportedTransactionType($initialSource);
        if ($typeResult instanceof DeferredCostDeliveryFailure) {
            return $typeResult;
        }

        /** @var array<string, array{source:InventoryTransaction,outbox:OutboxMessage,is_partner:bool}> $initialLegs */
        $initialLegs = [
            'source' => [
                'source' => $initialSource,
                'outbox' => $initialOutbox,
                'is_partner' => false,
            ],
        ];
        if ($typeResult) {
            $partner = $this->resolveTransferPartnerCandidate($initialSource);
            if ($partner instanceof DeferredCostDeliveryFailure) {
                return $partner;
            }
            $partnerModeFailure = $this->validateDeferredStampShape($partner, true);
            if ($partnerModeFailure !== null) {
                return $partnerModeFailure;
            }
            $initialLegs['partner'] = [
                'source' => $partner,
                'outbox' => null,
                'is_partner' => true,
            ];
        }

        // Canonical serialization latch and lock order starts here.
        $initialOwnership = CostDeliveryModeOwnership::find($initialSource->cost_delivery_ownership_id);
        if ($initialOwnership !== null
            && ($initialOwnership->property_id !== $initialSource->property_id
                || $initialOwnership->item_id !== $initialSource->item_id)) {
            return $this->failure('OWNERSHIP_IDENTITY_MISMATCH');
        }

        $pilot = CostDeliveryPilotProperty::where('pilot_slot', 1)
            ->where('property_id', $initialSource->property_id)
            ->lockForUpdate()
            ->first();
        if ($pilot === null) {
            return $this->failure('PILOT_NOT_AUTHORIZED');
        }

        $ownership = CostDeliveryModeOwnership::whereKey($initialSource->cost_delivery_ownership_id)
            ->lockForUpdate()
            ->first();
        if ($ownership === null) {
            return $this->failure('OWNERSHIP_NOT_FOUND');
        }
        $ownershipFailure = $this->validateOwnership($initialSource, $ownership);
        if ($ownershipFailure !== null) {
            return $ownershipFailure;
        }
        if (isset($initialLegs['partner'])) {
            $partnerOwnershipFailure = $this->validatePartnerOwnership(
                $initialLegs['partner']['source'],
                $ownership,
            );
            if ($partnerOwnershipFailure !== null) {
                return $partnerOwnershipFailure;
            }
        }

        $cutover = CostDeliveryCutover::whereKey($ownership->activated_cutover_id)
            ->where('ownership_id', $ownership->id)
            ->lockForUpdate()
            ->first();
        if ($cutover === null) {
            return $this->failure('CUTOVER_NOT_FOUND');
        }
        if ($cutover->property_id !== $initialSource->property_id
            || $cutover->item_id !== $initialSource->item_id
            || $cutover->enrollment_group_id !== $ownership->enrollment_group_id) {
            return $this->failure('CUTOVER_IDENTITY_MISMATCH');
        }

        $group = CostAuthorityEnrollmentGroup::find($ownership->enrollment_group_id);
        if ($group === null) {
            return $this->failure('ENROLLMENT_NOT_FOUND');
        }
        if ($group->status !== CostAuthorityEnrollmentStatusEnum::Enrolled) {
            return $this->failure('ENROLLMENT_NOT_ENROLLED');
        }
        if ($group->property_id !== $initialSource->property_id || $group->item_id !== $initialSource->item_id) {
            return $this->failure('ENROLLMENT_IDENTITY_MISMATCH');
        }

        $orderedInitialLegs = $this->orderLegsByScope($initialLegs);
        $snapshots = [];
        foreach ($orderedInitialLegs as $legName => $leg) {
            $snapshot = $this->resolveEnrollmentSnapshot($group, $leg['source'], $leg['is_partner']);
            if ($snapshot instanceof DeferredCostDeliveryFailure) {
                return $snapshot;
            }
            $snapshots[$legName] = $snapshot;
        }

        // Both transfer cutover scopes are locked in canonical valuation_scope order.
        $cutoverScopes = [];
        foreach ($orderedInitialLegs as $legName => $leg) {
            $cutoverScope = CostDeliveryCutoverScope::where('cutover_id', $cutover->id)
                ->where('property_id', $leg['source']->property_id)
                ->where('location_id', $leg['source']->location_id)
                ->where('item_id', $leg['source']->item_id)
                ->where('valuation_scope', $leg['source']->valuation_scope)
                ->lockForUpdate()
                ->first();
            if ($cutoverScope === null) {
                return $this->failure(
                    $leg['is_partner'] ? 'TRANSFER_PAIR_CUTOVER_SCOPE_MISSING' : 'CUTOVER_SCOPE_NOT_FOUND',
                );
            }
            $referencedSnapshot = CostAuthorityEnrollmentScopeSnapshot::whereKey(
                $cutoverScope->enrollment_scope_snapshot_id,
            )->where('enrollment_group_id', $group->id)->first();
            if ($referencedSnapshot === null) {
                return $this->failure(
                    $leg['is_partner'] ? 'TRANSFER_PAIR_SCOPE_SNAPSHOT_MISSING' : 'SCOPE_SNAPSHOT_NOT_FOUND',
                );
            }
            if ($referencedSnapshot->id !== $snapshots[$legName]->id) {
                return $this->failure(
                    $leg['is_partner'] ? 'TRANSFER_PAIR_SCOPE_SNAPSHOT_MISMATCH' : 'SCOPE_SNAPSHOT_MISMATCH',
                );
            }
            $cutoverScopes[$legName] = $cutoverScope;
        }

        if (isset($initialLegs['partner'])) {
            $partnerOutbox = $this->resolvePartnerOutboxCandidate($initialLegs['partner']['source']->id);
            if ($partnerOutbox instanceof DeferredCostDeliveryFailure) {
                return $partnerOutbox;
            }
            $initialLegs['partner']['outbox'] = $partnerOutbox;
        }

        // Lock both Outbox/source/disposition evidence sets in Outbox ULID order.
        $lockedLegs = [];
        foreach ($this->orderLegsByOutbox($initialLegs) as $legName => $leg) {
            $outbox = OutboxMessage::whereKey($leg['outbox']->id)->lockForUpdate()->first();
            if ($outbox === null) {
                return $this->failure($leg['is_partner'] ? 'TRANSFER_PAIR_OUTBOX_MISSING' : 'OUTBOX_NOT_FOUND');
            }

            $lockedSourceId = $this->validatedOutboxSourceId($outbox, $leg['is_partner']);
            if ($lockedSourceId instanceof DeferredCostDeliveryFailure) {
                return $lockedSourceId;
            }
            if ($lockedSourceId !== $leg['source']->id) {
                return $this->failure(
                    $leg['is_partner']
                        ? 'TRANSFER_PAIR_OUTBOX_SOURCE_CHANGED_DURING_PROOF'
                        : 'OUTBOX_SOURCE_CHANGED_DURING_PROOF',
                );
            }

            $source = InventoryTransaction::whereKey($leg['source']->id)->lockForUpdate()->first();
            if ($source === null) {
                return $this->failure(
                    $leg['is_partner'] ? 'TRANSFER_PAIR_INVENTORY_SOURCE_NOT_FOUND' : 'INVENTORY_SOURCE_NOT_FOUND',
                );
            }
            if (! $this->sameImmutableSourceIdentity($source, $leg['source'])) {
                return $this->failure(
                    $leg['is_partner']
                        ? 'TRANSFER_PAIR_SOURCE_CHANGED_DURING_PROOF'
                        : 'SOURCE_CHANGED_DURING_PROOF',
                );
            }

            $modeFailure = $this->validateDeferredStampShape($source, $leg['is_partner']);
            if ($modeFailure !== null) {
                return $modeFailure;
            }
            $stampFailure = $leg['is_partner']
                ? $this->validatePartnerOwnership($source, $ownership)
                : $this->validateLockedSourceOwnership($source, $ownership, $cutover);
            if ($stampFailure !== null) {
                return $stampFailure;
            }
            $sourceFailure = $this->validateCanonicalSource($source, $leg['is_partner']);
            if ($sourceFailure !== null) {
                return $sourceFailure;
            }

            $disposition = $this->lockDisposition($outbox, $source, $leg['is_partner']);
            if ($disposition instanceof DeferredCostDeliveryFailure) {
                return $disposition;
            }
            $lockedLegs[$legName] = [
                'source' => $source,
                'outbox' => $outbox,
                'disposition' => $disposition,
                'is_partner' => $leg['is_partner'],
            ];
        }

        if (isset($lockedLegs['partner'])) {
            $pairFailure = $this->validateTransferPairFacts(
                $lockedLegs['source']['source'],
                $lockedLegs['partner']['source'],
            );
            if ($pairFailure !== null) {
                return $pairFailure;
            }
        }

        foreach ($lockedLegs as $legName => $leg) {
            $cutoverScope = $cutoverScopes[$legName];
            if ($leg['source']->valuation_sequence < $cutoverScope->first_deferred_owned_sequence) {
                return $this->failure(
                    $leg['is_partner'] ? 'TRANSFER_PAIR_WATERMARK_NOT_REACHED' : 'WATERMARK_NOT_REACHED',
                    [
                        'affected_scope' => $leg['source']->valuation_scope,
                        'first_deferred_owned_sequence' => $cutoverScope->first_deferred_owned_sequence,
                        'source_valuation_sequence' => $leg['source']->valuation_sequence,
                    ],
                );
            }
        }

        $dateFailure = $this->lockAndValidateDateAndPeriod($lockedLegs);
        if ($dateFailure !== null) {
            return $dateFailure;
        }

        // Lock both AVCO rows in canonical scope order.
        $expectedSequences = [];
        foreach ($orderedInitialLegs as $legName => $initialLeg) {
            $leg = $lockedLegs[$legName];
            $avcoState = CostAvcoState::where('property_id', $leg['source']->property_id)
                ->where('location_id', $leg['source']->location_id)
                ->where('item_id', $leg['source']->item_id)
                ->lockForUpdate()
                ->first();
            if ($avcoState === null || $avcoState->valuation_scope !== $leg['source']->valuation_scope) {
                return $this->failure(
                    $leg['is_partner'] ? 'TRANSFER_PAIR_AVCO_STATE_MISSING' : 'AVCO_STATE_MISSING',
                );
            }
            $expectedSequences[$legName] = $avcoState->last_valuation_sequence === null
                ? 1
                : $avcoState->last_valuation_sequence + 1;
        }

        // Final source arbitration for both legs follows the same deterministic order.
        $equivalences = [];
        foreach ($lockedLegs as $legName => $leg) {
            $equivalence = $this->costLedgerRepository->resolveInventoryTransaction($leg['source'], true);
            if ($equivalence->status === CostLedgerSourceEquivalence::CONFLICTING_EFFECT) {
                return $this->failure(
                    $leg['is_partner']
                        ? 'TRANSFER_PAIR_COST_LEDGER_CONFLICTING_EFFECT'
                        : 'COST_LEDGER_CONFLICTING_EFFECT',
                );
            }
            if ($equivalence->status === CostLedgerSourceEquivalence::LEGACY_SOURCE_DUPLICATE_CONTRADICTION) {
                return $this->failure(
                    $leg['is_partner']
                        ? 'TRANSFER_PAIR_COST_LEDGER_SOURCE_DUPLICATE_CONTRADICTION'
                        : 'COST_LEDGER_SOURCE_DUPLICATE_CONTRADICTION',
                    [
                        'affected_scope' => $leg['source']->valuation_scope,
                        'source_row_count' => $equivalence->sourceRowCount,
                    ],
                );
            }
            $equivalences[$legName] = $equivalence;
        }

        $alreadySatisfied = [];
        foreach ($lockedLegs as $legName => $leg) {
            $expectedSequence = $expectedSequences[$legName];
            $equivalence = $equivalences[$legName];
            if ($leg['source']->valuation_sequence > $expectedSequence) {
                return $this->failure('BLOCKED_SEQUENCE', [
                    'affected_leg' => $leg['is_partner'] ? 'partner' : 'source',
                    'affected_scope' => $leg['source']->valuation_scope,
                    'affected_source_inventory_transaction_id' => $leg['source']->id,
                    'expected_sequence' => $expectedSequence,
                    'source_valuation_sequence' => $leg['source']->valuation_sequence,
                ]);
            }

            $alreadySatisfied[$legName] = $equivalence->status
                === CostLedgerSourceEquivalence::EXACT_EQUIVALENT_EFFECT;
            if ($leg['source']->valuation_sequence < $expectedSequence && ! $alreadySatisfied[$legName]) {
                return $this->failure(
                    $leg['is_partner']
                        ? 'TRANSFER_PAIR_SOURCE_SEQUENCE_BEHIND_CONTRADICTION'
                        : 'SOURCE_SEQUENCE_BEHIND_CONTRADICTION',
                    [
                        'affected_scope' => $leg['source']->valuation_scope,
                        'expected_sequence' => $expectedSequence,
                        'source_valuation_sequence' => $leg['source']->valuation_sequence,
                    ],
                );
            }
        }

        $legContexts = [];
        foreach ($lockedLegs as $legName => $leg) {
            $legContexts[$legName] = new DeferredCostDeliveryEligibleLegContext(
                outboxMessageId: $leg['outbox']->id,
                sourceInventoryTransactionId: $leg['source']->id,
                propertyId: $leg['source']->property_id,
                locationId: $leg['source']->location_id,
                itemId: $leg['source']->item_id,
                valuationScope: $leg['source']->valuation_scope,
                valuationSequence: $leg['source']->valuation_sequence,
                cutoverScopeId: $cutoverScopes[$legName]->id,
                enrollmentScopeSnapshotId: $snapshots[$legName]->id,
                firstDeferredOwnedSequence: $cutoverScopes[$legName]->first_deferred_owned_sequence,
                dispositionId: $leg['disposition']->id,
                processingState: $leg['disposition']->processing_state->value,
                sourceEquivalence: $equivalences[$legName],
                expectedSequence: $expectedSequences[$legName],
                alreadySatisfied: $alreadySatisfied[$legName],
            );
        }

        $source = $lockedLegs['source']['source'];
        $sourceDisposition = $lockedLegs['source']['disposition'];
        $partnerSource = $lockedLegs['partner']['source'] ?? null;

        return new DeferredCostDeliveryEligibleContext(
            outboxMessageId: $lockedLegs['source']['outbox']->id,
            sourceInventoryTransactionId: $source->id,
            propertyId: $source->property_id,
            locationId: $source->location_id,
            itemId: $source->item_id,
            valuationScope: $source->valuation_scope,
            valuationSequence: $source->valuation_sequence,
            ownershipId: $ownership->id,
            ownershipVersion: $ownership->ownership_version,
            cutoverId: $cutover->id,
            dispositionId: $sourceDisposition->id,
            processingState: $sourceDisposition->processing_state->value,
            sourceEquivalence: $equivalences['source'],
            expectedSequence: $expectedSequences['source'],
            alreadySatisfied: $alreadySatisfied['source'],
            requiresPairedApplication: $partnerSource !== null,
            pairedInventoryTransactionId: $partnerSource?->id,
            sourceLeg: $legContexts['source'],
            pairedLeg: $legContexts['partner'] ?? null,
        );
    }

    /** @param array<string, array{source:InventoryTransaction,outbox:?OutboxMessage,is_partner:bool}> $legs */
    private function orderLegsByScope(array $legs): array
    {
        uasort($legs, function (array $left, array $right): int {
            $scopeOrder = $left['source']->valuation_scope <=> $right['source']->valuation_scope;

            return $scopeOrder !== 0
                ? $scopeOrder
                : $left['source']->id <=> $right['source']->id;
        });

        return $legs;
    }

    /** @param array<string, array{source:InventoryTransaction,outbox:OutboxMessage,is_partner:bool}> $legs */
    private function orderLegsByOutbox(array $legs): array
    {
        uasort($legs, fn (array $left, array $right): int => $left['outbox']->id <=> $right['outbox']->id);

        return $legs;
    }

    private function validateOwnership(
        InventoryTransaction $source,
        CostDeliveryModeOwnership $ownership,
    ): ?DeferredCostDeliveryFailure {
        if ($ownership->delivery_mode !== CostDeliveryMode::Deferred) {
            return $this->failure('OWNERSHIP_NOT_DEFERRED');
        }
        if ($ownership->property_id !== $source->property_id || $ownership->item_id !== $source->item_id) {
            return $this->failure('OWNERSHIP_IDENTITY_MISMATCH');
        }
        if ($ownership->ownership_version !== $source->cost_delivery_ownership_version) {
            return $this->failure('OWNERSHIP_VERSION_MISMATCH');
        }
        if ($ownership->activated_cutover_id !== $source->cost_delivery_cutover_id) {
            return $this->failure('OWNERSHIP_CUTOVER_MISMATCH');
        }

        return null;
    }

    private function validatePartnerOwnership(
        InventoryTransaction $partner,
        CostDeliveryModeOwnership $ownership,
    ): ?DeferredCostDeliveryFailure {
        if ($partner->property_id !== $ownership->property_id || $partner->item_id !== $ownership->item_id) {
            return $this->failure('TRANSFER_PAIR_OWNERSHIP_IDENTITY_MISMATCH');
        }
        if ($partner->cost_delivery_ownership_id !== $ownership->id) {
            return $this->failure('TRANSFER_PAIR_OWNERSHIP_MISMATCH');
        }
        if ($partner->cost_delivery_ownership_version !== $ownership->ownership_version) {
            return $this->failure('TRANSFER_PAIR_OWNERSHIP_VERSION_MISMATCH');
        }
        if ($partner->cost_delivery_cutover_id !== $ownership->activated_cutover_id) {
            return $this->failure('TRANSFER_PAIR_CUTOVER_MISMATCH');
        }

        return null;
    }

    private function validateLockedSourceOwnership(
        InventoryTransaction $source,
        CostDeliveryModeOwnership $ownership,
        CostDeliveryCutover $cutover,
    ): ?DeferredCostDeliveryFailure {
        if ($source->cost_delivery_ownership_id !== $ownership->id
            || $source->cost_delivery_ownership_version !== $ownership->ownership_version
            || $source->cost_delivery_cutover_id !== $cutover->id) {
            return $this->failure('SOURCE_OWNERSHIP_STAMP_CHANGED_DURING_PROOF');
        }

        return null;
    }

    private function resolveEnrollmentSnapshot(
        CostAuthorityEnrollmentGroup $group,
        InventoryTransaction $source,
        bool $partner,
    ): CostAuthorityEnrollmentScopeSnapshot|DeferredCostDeliveryFailure {
        $snapshots = CostAuthorityEnrollmentScopeSnapshot::where('enrollment_group_id', $group->id)
            ->where('location_id', $source->location_id)
            ->where('valuation_scope', $source->valuation_scope)
            ->orderBy('id')
            ->get();
        if ($snapshots->count() !== 1) {
            return $this->failure(
                $partner ? 'TRANSFER_PAIR_SCOPE_SNAPSHOT_MISSING' : 'SCOPE_SNAPSHOT_NOT_FOUND',
            );
        }

        $snapshot = $snapshots->first();
        $expectedScope = "property:{$group->property_id}:location:{$source->location_id}:item:{$group->item_id}";
        if ($snapshot->location_id !== $source->location_id
            || $snapshot->valuation_scope !== $expectedScope
            || $source->valuation_scope !== $expectedScope) {
            return $this->failure(
                $partner ? 'TRANSFER_PAIR_SCOPE_SNAPSHOT_MISMATCH' : 'SCOPE_SNAPSHOT_MISMATCH',
            );
        }

        return $snapshot;
    }

    private function resolvePartnerOutboxCandidate(string $sourceId): OutboxMessage|DeferredCostDeliveryFailure
    {
        $outboxes = OutboxMessage::where('source_inventory_transaction_id', $sourceId)
            ->orderBy('id')
            ->get();
        if ($outboxes->count() !== 1) {
            return $this->failure('TRANSFER_PAIR_OUTBOX_MISSING');
        }

        return $outboxes->first();
    }

    private function lockDisposition(
        OutboxMessage $outbox,
        InventoryTransaction $source,
        bool $partner,
    ): CostDeliveryOutboxDisposition|DeferredCostDeliveryFailure {
        $dispositions = CostDeliveryOutboxDisposition::where('outbox_message_id', $outbox->id)
            ->orWhere('source_inventory_transaction_id', $source->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($dispositions->count() !== 1) {
            return $this->failure($partner ? 'TRANSFER_PAIR_DISPOSITION_MISSING' : 'DISPOSITION_NOT_FOUND');
        }

        $disposition = $dispositions->first();
        if ($disposition->outbox_message_id !== $outbox->id
            || $disposition->source_inventory_transaction_id !== $source->id
            || $disposition->property_id !== $source->property_id
            || $disposition->location_id !== $source->location_id
            || $disposition->item_id !== $source->item_id
            || $disposition->valuation_scope !== $source->valuation_scope
            || $disposition->valuation_sequence !== $source->valuation_sequence
            || $disposition->cost_delivery_ownership_id !== $source->cost_delivery_ownership_id
            || $disposition->cost_delivery_ownership_version !== $source->cost_delivery_ownership_version
            || $disposition->cost_delivery_cutover_id !== $source->cost_delivery_cutover_id) {
            return $this->failure(
                $partner ? 'TRANSFER_PAIR_DISPOSITION_IDENTITY_MISMATCH' : 'DISPOSITION_IDENTITY_MISMATCH',
            );
        }
        if ($disposition->classification !== CostDeliveryDispositionClass::DeferredOwnedAfterCutover) {
            return $this->failure(
                $partner
                    ? 'TRANSFER_PAIR_DISPOSITION_CLASSIFICATION_INELIGIBLE'
                    : 'DISPOSITION_CLASSIFICATION_INELIGIBLE',
            );
        }
        if (! in_array($disposition->processing_state, [
            CostDeliveryProcessingState::Pending,
            CostDeliveryProcessingState::Failed,
            CostDeliveryProcessingState::BlockedSequence,
        ], true)) {
            return $this->failure(
                $partner ? 'TRANSFER_PAIR_DISPOSITION_STATE_INELIGIBLE' : 'DISPOSITION_STATE_INELIGIBLE',
            );
        }

        return $disposition;
    }

    /**
     * @param  array<string, array{source:InventoryTransaction,outbox:OutboxMessage,disposition:CostDeliveryOutboxDisposition,is_partner:bool}>  $legs
     */
    private function lockAndValidateDateAndPeriod(array $legs): ?DeferredCostDeliveryFailure
    {
        $businessDates = [];
        foreach ($legs as $leg) {
            $key = $leg['source']->property_id.':'.$leg['source']->business_date->format('Y-m-d');
            $businessDates[$key] = $leg;
        }
        ksort($businessDates);
        foreach ($businessDates as $leg) {
            $source = $leg['source'];
            $businessDate = PropertyBusinessDate::where('property_id', $source->property_id)
                ->whereDate('business_date', $source->business_date->format('Y-m-d'))
                ->lockForUpdate()
                ->first();
            if ($businessDate === null) {
                return $this->failure(
                    $leg['is_partner'] ? 'TRANSFER_PAIR_BUSINESS_DATE_MISSING' : 'BUSINESS_DATE_MISSING',
                );
            }
            if ($businessDate->status !== PropertyBusinessDateStatusEnum::Open || ! $businessDate->is_open) {
                return $this->failure(
                    $leg['is_partner'] ? 'TRANSFER_PAIR_BUSINESS_DATE_CLOSED' : 'BUSINESS_DATE_CLOSED',
                );
            }
        }

        $periodIds = [];
        foreach ($legs as $leg) {
            $periodIds[$leg['source']->financial_period_id] = true;
        }
        ksort($periodIds);
        $periods = [];
        foreach (array_keys($periodIds) as $periodId) {
            $period = FinancialPeriod::whereKey($periodId)->lockForUpdate()->first();
            if ($period !== null) {
                $periods[$periodId] = $period;
            }
        }

        foreach ($legs as $leg) {
            $source = $leg['source'];
            $period = $periods[$source->financial_period_id] ?? null;
            if ($period === null) {
                return $this->failure(
                    $leg['is_partner'] ? 'TRANSFER_PAIR_FINANCIAL_PERIOD_MISSING' : 'FINANCIAL_PERIOD_MISSING',
                );
            }
            if ($period->property_id !== $source->property_id) {
                return $this->failure(
                    $leg['is_partner']
                        ? 'TRANSFER_PAIR_FINANCIAL_PERIOD_PROPERTY_MISMATCH'
                        : 'FINANCIAL_PERIOD_PROPERTY_MISMATCH',
                );
            }
            if ($period->period_year !== (int) $source->occurred_at->format('Y')
                || $period->period_month !== (int) $source->occurred_at->format('n')) {
                return $this->failure(
                    $leg['is_partner']
                        ? 'TRANSFER_PAIR_FINANCIAL_PERIOD_DATE_MISMATCH'
                        : 'FINANCIAL_PERIOD_DATE_MISMATCH',
                );
            }
            if (! in_array($period->status, [
                FinancialPeriodStatusEnum::Open,
                FinancialPeriodStatusEnum::Reopened,
            ], true)) {
                return $this->failure(
                    $leg['is_partner']
                        ? 'TRANSFER_PAIR_FINANCIAL_PERIOD_STATE_INELIGIBLE'
                        : 'FINANCIAL_PERIOD_STATE_INELIGIBLE',
                );
            }
        }

        return null;
    }

    private function validatedOutboxSourceId(
        OutboxMessage $outbox,
        bool $partner,
    ): string|DeferredCostDeliveryFailure {
        $prefix = $partner ? 'TRANSFER_PAIR_' : '';
        if ($outbox->topic !== 'inventory.transaction.posted') {
            return $this->failure($prefix.'OUTBOX_TOPIC_INVALID');
        }
        if (! in_array($outbox->status, [OutboxStatusEnum::Pending, OutboxStatusEnum::Failed], true)) {
            return $this->failure($prefix.'OUTBOX_STATE_INELIGIBLE');
        }

        $sourceId = trim((string) $outbox->source_inventory_transaction_id);
        if ($sourceId === '') {
            return $this->failure($prefix.'OUTBOX_SOURCE_ID_MISSING');
        }
        $payload = $outbox->payload;
        if (! is_array($payload)
            || count($payload) !== 1
            || ! array_key_exists('transactionId', $payload)
            || ! is_string($payload['transactionId'])
            || $payload['transactionId'] !== $sourceId) {
            return $this->failure($prefix.'OUTBOX_PAYLOAD_SOURCE_MISMATCH');
        }
        if ($outbox->idempotency_key !== "inventory_transaction:{$sourceId}:cost_ledger") {
            return $this->failure($prefix.'OUTBOX_SOURCE_IDENTITY_MISMATCH');
        }

        return $sourceId;
    }

    private function validateDeferredStampShape(
        InventoryTransaction $source,
        bool $partner,
    ): ?DeferredCostDeliveryFailure {
        $prefix = $partner ? 'TRANSFER_PAIR_' : '';
        if ($source->cost_delivery_mode === null) {
            return $this->failure($prefix.'HISTORICAL_NULL_STAMP_NOT_DEFERRED_ELIGIBLE');
        }
        if ($source->cost_delivery_mode === CostDeliveryMode::Synchronous->value) {
            return $this->failure($prefix.'SYNCHRONOUS_SOURCE_NOT_DEFERRED_ELIGIBLE');
        }
        if ($source->cost_delivery_mode !== CostDeliveryMode::Deferred->value) {
            return $this->failure($prefix.'SOURCE_DELIVERY_MODE_INVALID');
        }
        if (trim((string) $source->cost_delivery_ownership_id) === ''
            || $source->cost_delivery_ownership_version === null
            || $source->cost_delivery_ownership_version < 1
            || trim((string) $source->cost_delivery_cutover_id) === '') {
            return $this->failure($prefix.'SOURCE_OWNERSHIP_EVIDENCE_INCOMPLETE');
        }

        return null;
    }

    private function validateCanonicalSource(
        InventoryTransaction $source,
        bool $partner,
    ): ?DeferredCostDeliveryFailure {
        $prefix = $partner ? 'TRANSFER_PAIR_' : '';
        if (trim((string) $source->property_id) === ''
            || trim((string) $source->item_id) === ''
            || trim((string) $source->location_id) === ''
            || trim((string) $source->valuation_scope) === ''
            || $source->valuation_sequence === null
            || $source->valuation_sequence < 1
            || $source->business_date === null
            || trim((string) $source->financial_period_id) === ''
            || trim((string) $source->currency_code) === ''
            || $source->occurred_at === null) {
            return $this->failure($prefix.'SOURCE_EVIDENCE_INCOMPLETE');
        }

        $expectedScope = "property:{$source->property_id}:location:{$source->location_id}:item:{$source->item_id}";
        if ($source->valuation_scope !== $expectedScope) {
            return $this->failure($prefix.'SOURCE_SCOPE_MISMATCH');
        }
        $item = InventoryItem::find($source->item_id);
        $location = InventoryLocation::find($source->location_id);
        if ($item === null
            || $location === null
            || $item->property_id !== $source->property_id
            || $location->property_id !== $source->property_id) {
            return $this->failure($prefix.'SOURCE_PROPERTY_MISMATCH');
        }

        return null;
    }

    private function validateSupportedTransactionType(
        InventoryTransaction $source,
    ): bool|DeferredCostDeliveryFailure {
        $transactionType = $this->transactionType($source);
        if ($transactionType === TransactionTypeEnum::OpeningBalance) {
            return $this->failure('OPENING_BALANCE_UNSUPPORTED');
        }
        if ($transactionType === TransactionTypeEnum::Return) {
            return $this->failure('RETURN_UNSUPPORTED');
        }
        if (! in_array($transactionType, [
            TransactionTypeEnum::PurchaseReceipt,
            TransactionTypeEnum::Issue,
            TransactionTypeEnum::AdjustmentIn,
            TransactionTypeEnum::AdjustmentOut,
            TransactionTypeEnum::Reversal,
            TransactionTypeEnum::TransferOut,
            TransactionTypeEnum::TransferIn,
        ], true)) {
            return $this->failure('TRANSACTION_TYPE_UNSUPPORTED');
        }

        return in_array($transactionType, [
            TransactionTypeEnum::TransferOut,
            TransactionTypeEnum::TransferIn,
        ], true);
    }

    private function resolveTransferPartnerCandidate(
        InventoryTransaction $source,
    ): InventoryTransaction|DeferredCostDeliveryFailure {
        if (trim((string) $source->source_document_id) === ''
            || trim((string) $source->source_line_id) === '') {
            return $this->failure('TRANSFER_PAIR_EVIDENCE_INCOMPLETE');
        }

        $partnerType = $this->transactionType($source) === TransactionTypeEnum::TransferOut
            ? TransactionTypeEnum::TransferIn
            : TransactionTypeEnum::TransferOut;
        $partners = InventoryTransaction::where('property_id', $source->property_id)
            ->where('source_document_id', $source->source_document_id)
            ->where('source_line_id', $source->source_line_id)
            ->where('transaction_type', $partnerType->value)
            ->orderBy('id')
            ->get();
        if ($partners->count() !== 1) {
            return $this->failure('TRANSFER_PAIR_EVIDENCE_INCOMPLETE');
        }

        return $partners->first();
    }

    private function validateTransferPairFacts(
        InventoryTransaction $source,
        InventoryTransaction $partner,
    ): ?DeferredCostDeliveryFailure {
        $sourceType = $this->transactionType($source);
        $partnerType = $this->transactionType($partner);
        $opposite = ($sourceType === TransactionTypeEnum::TransferOut && $partnerType === TransactionTypeEnum::TransferIn)
            || ($sourceType === TransactionTypeEnum::TransferIn && $partnerType === TransactionTypeEnum::TransferOut);

        if (! $opposite
            || $partner->property_id !== $source->property_id
            || $partner->item_id !== $source->item_id
            || $partner->source_document_id !== $source->source_document_id
            || $partner->source_line_id !== $source->source_line_id
            || $partner->location_id === $source->location_id
            || $partner->business_date?->format('Y-m-d') !== $source->business_date?->format('Y-m-d')
            || $partner->financial_period_id !== $source->financial_period_id
            || $partner->currency_code !== $source->currency_code
            || $partner->cost_delivery_mode !== CostDeliveryMode::Deferred->value
            || $partner->cost_delivery_ownership_id !== $source->cost_delivery_ownership_id
            || $partner->cost_delivery_ownership_version !== $source->cost_delivery_ownership_version
            || $partner->cost_delivery_cutover_id !== $source->cost_delivery_cutover_id
            || bccomp((string) $partner->unit_cost, (string) $source->unit_cost, 4) !== 0
            || bccomp((string) $partner->quantity_change, bcmul((string) $source->quantity_change, '-1', 4), 4) !== 0
            || bccomp((string) $partner->total_cost, bcmul((string) $source->total_cost, '-1', 4), 4) !== 0) {
            return $this->failure('TRANSFER_PAIR_EVIDENCE_CONFLICT');
        }

        return null;
    }

    private function sameImmutableSourceIdentity(
        InventoryTransaction $locked,
        InventoryTransaction $initial,
    ): bool {
        return $locked->id === $initial->id
            && $locked->property_id === $initial->property_id
            && $locked->item_id === $initial->item_id
            && $locked->location_id === $initial->location_id
            && $locked->valuation_scope === $initial->valuation_scope
            && $locked->valuation_sequence === $initial->valuation_sequence
            && $locked->financial_period_id === $initial->financial_period_id
            && $locked->business_date?->format('Y-m-d') === $initial->business_date?->format('Y-m-d')
            && $locked->occurred_at?->getTimestamp() === $initial->occurred_at?->getTimestamp()
            && $locked->source_document_id === $initial->source_document_id
            && $locked->source_line_id === $initial->source_line_id
            && $this->transactionType($locked) === $this->transactionType($initial);
    }

    private function transactionType(InventoryTransaction $source): ?TransactionTypeEnum
    {
        return $source->transaction_type instanceof TransactionTypeEnum
            ? $source->transaction_type
            : TransactionTypeEnum::tryFrom((string) $source->transaction_type);
    }

    /** @param array<string, int|string> $evidence */
    private function failure(string $code, array $evidence = []): DeferredCostDeliveryFailure
    {
        return new DeferredCostDeliveryFailure($code, $evidence);
    }
}
