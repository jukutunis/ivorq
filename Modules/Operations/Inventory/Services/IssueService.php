<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\Inventory\Contracts\AuthoritativeInventoryCostPort;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Inventory\Contracts\SynchronousCostValuationPort;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Repositories\InventoryIssueRepository;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use RuntimeException;
use Shared\Exceptions\BusinessLogicException;

class IssueService
{
    public function __construct(
        private InventoryIssueRepository $issueRepository,
        private InventoryPostingControlCoordinator $coordinator,
        private InventoryItemRepository $itemRepository,
        private CostDeliveryModePort $costDeliveryMode,
        private AuthoritativeInventoryCostPort $authoritativeCost,
        private SynchronousCostValuationPort $synchronousValuation,
    ) {}

    public function create(array $data): InventoryIssue
    {
        return DB::transaction(function () use ($data): InventoryIssue {
            $this->lockMutationItems((string) $data['property_id'], collect($data['lines'] ?? [])->pluck('item_id')->all());
            $data['status'] = IssueStatusEnum::Draft->value;

            return $this->issueRepository->create($data);
        });
    }

    public function post(string $id, ?string $userId = null): InventoryIssue
    {
        $issue = $this->issueRepository->find($id);

        if (! $issue->status->canTransitionTo(IssueStatusEnum::Posted)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition issue from {$issue->status->label()} to Posted."],
            ]);
        }

        // BR-041: at least one line required
        if ($issue->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => ['An issue must have at least one line before it can be posted.'],
            ]);
        }

        $businessDate = PropertyBusinessDate::where('property_id', $issue->property_id)
            ->where('status', PropertyBusinessDateStatusEnum::Open)
            ->where('is_open', true)
            ->first();

        if (! $businessDate) {
            throw new BusinessLogicException('No open business date found for property.');
        }

        $authId = auth()->id();
        if (! $authId) {
            throw new BusinessLogicException('Authenticated posting operator is required.');
        }

        if ($userId !== null && $userId !== $authId) {
            throw new BusinessLogicException('The supplied user ID does not match the authenticated posting operator.');
        }

        $actorId = $authId;

        // Deterministic multi-line order: item_id ASC -> location_id ASC -> id ASC
        $sortedLines = $issue->lines->map(function ($line) {
            if (! $line->item_id || ! $line->location_id) {
                throw new BusinessLogicException('Issue line is missing item or location.');
            }

            return $line;
        })->sortBy([
            ['item_id', 'asc'],
            ['location_id', 'asc'],
            ['id', 'asc'],
        ]);

        // Enrollment guard and classification
        $propertyId = $issue->property_id;
        $totalLines = $sortedLines->count();
        $enrolledCount = 0;
        foreach ($sortedLines as $line) {
            if ($this->costDeliveryMode->isEnrolled($propertyId, (string) $line->item_id)) {
                $enrolledCount++;
            }
        }

        if ($enrolledCount > 0 && $enrolledCount < $totalLines) {
            throw new RuntimeException('Mixed enrollment status detected across issue lines. Fail closed.');
        }

        $allEnrolled = ($enrolledCount === $totalLines);

        if (! $allEnrolled) {
            // Legacy path remains unchanged
            DB::transaction(function () use ($issue, $sortedLines, $businessDate, $actorId) {
                $occurredAt = Carbon::parse($issue->issued_at ?? $issue->created_at ?? now());

                // Acquire business date and financial period locks
                $this->coordinator->lockContext($issue->property_id, $businessDate->business_date, $occurredAt);

                foreach ($sortedLines as $line) {
                    $item = $this->itemRepository->find($line->item_id);
                    if (! $item) {
                        throw new BusinessLogicException("Item not found: {$line->item_id}");
                    }

                    $wac = $item->weighted_average_cost;
                    if ($wac === null) {
                        throw ValidationException::withMessages([
                            'cost' => ["Item {$item->name} ({$item->sku}) does not have a valid weighted average cost."],
                        ]);
                    }

                    // quantityChange = negative line quantity
                    $qtyChange = (string) (-1 * abs((float) $line->quantity));

                    // totalCost = qtyChange * unitCost
                    $totalCost = bcmul($qtyChange, (string) $wac, 4);

                    $intent = new InventoryLedgerPostingIntent(
                        propertyId: $issue->property_id,
                        itemId: $line->item_id,
                        locationId: $line->location_id,
                        businessDate: $businessDate->business_date,
                        occurredAt: $occurredAt,
                        sourceDocumentType: 'inventory_issue',
                        sourceDocumentId: $issue->id,
                        sourceLineType: 'inventory_issue_line',
                        sourceLineId: $line->id,
                        movementRole: TransactionTypeEnum::Issue->value,
                        idempotencyKey: "iss_{$issue->id}_{$line->id}_post",
                        transactionType: TransactionTypeEnum::Issue,
                        quantityChange: $qtyChange,
                        unitCost: (string) $wac,
                        totalCost: $totalCost,
                        reference: $issue->issue_number,
                        notes: $issue->remarks ?? 'Inventory Issue Posting'
                    );

                    $this->coordinator->post($intent, $actorId);
                }

                $this->issueRepository->update($issue->id, [
                    'status' => IssueStatusEnum::Posted->value,
                    'posted_at' => now(),
                    'posted_by' => $actorId,
                ], true);
            });
        } else {
            // All ENROLLED loop
            DB::transaction(function () use ($issue, $sortedLines, $businessDate, $actorId, $propertyId) {
                $occurredAt = Carbon::parse($issue->issued_at ?? $issue->created_at ?? now());

                $sources = [];
                foreach ($sortedLines as $line) {
                    $sources[] = [
                        'propertyId' => $propertyId,
                        'itemId' => (string) $line->item_id,
                        'locationId' => (string) $line->location_id,
                        'idempotencyKey' => "iss_{$issue->id}_{$line->id}_post",
                        'sourceDocumentType' => 'inventory_issue',
                        'sourceDocumentId' => $issue->id,
                        'sourceLineType' => 'inventory_issue_line',
                        'sourceLineId' => $line->id,
                        'movementRole' => TransactionTypeEnum::Issue->value,
                        'quantityChange' => bcmul((string) abs((float) $line->quantity), '-1', 4),
                    ];
                }
                $resolved = $this->coordinator->resolveDocumentDeliveryModes($sources);

                foreach ($sortedLines as $line) {
                    $idempotencyKey = "iss_{$issue->id}_{$line->id}_post";
                    $resolution = $resolved[$idempotencyKey];
                    $unitCost = $resolution['existing'] !== null
                        ? (string) $resolution['existing']->unit_cost
                        : $this->authoritativeCost->resolveUnitCostForPosting($resolution['decision']);
                    $quantity = bcmul((string) abs((float) $line->quantity), '-1', 4);
                    $intent = new InventoryLedgerPostingIntent(
                        propertyId: $propertyId,
                        itemId: (string) $line->item_id,
                        locationId: (string) $line->location_id,
                        businessDate: $businessDate->business_date,
                        occurredAt: $occurredAt,
                        sourceDocumentType: 'inventory_issue',
                        sourceDocumentId: $issue->id,
                        sourceLineType: 'inventory_issue_line',
                        sourceLineId: $line->id,
                        movementRole: TransactionTypeEnum::Issue->value,
                        idempotencyKey: $idempotencyKey,
                        transactionType: TransactionTypeEnum::Issue,
                        quantityChange: $quantity,
                        unitCost: $unitCost,
                        totalCost: bcmul($quantity, $unitCost, 4),
                        reference: $issue->issue_number,
                        notes: $issue->remarks ?? 'Inventory Issue Posting',
                    );
                    $transaction = $this->coordinator->post($intent, $actorId, $resolution['decision']);
                    if ($resolution['existing'] === null
                        && $resolution['decision']->outcome === CostDeliveryPostingDecision::SYNCHRONOUS) {
                        $this->synchronousValuation->applyIssue($transaction->id);
                    }
                }

                $this->issueRepository->update($issue->id, [
                    'status' => IssueStatusEnum::Posted->value,
                    'posted_at' => now(),
                    'posted_by' => $actorId,
                ], true);
            });
        }

        return $this->issueRepository->find($id);
    }

    private function lockMutationItems(string $propertyId, array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
        sort($itemIds, SORT_STRING);
        foreach ($itemIds as $itemId) {
            $this->costDeliveryMode->lockForDocumentMutation($propertyId, $itemId);
        }
    }
}
