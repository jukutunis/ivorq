<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Repositories\InventoryIssueRepository;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Shared\Exceptions\BusinessLogicException;

class IssueService
{
    public function __construct(
        private InventoryIssueRepository $issueRepository,
        private InventoryPostingControlCoordinator $coordinator,
        private InventoryItemRepository $itemRepository
    ) {}

    public function create(array $data): InventoryIssue
    {
        $data['status'] = IssueStatusEnum::Draft->value;
        return $this->issueRepository->create($data);
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

        if (!$businessDate) {
            throw new BusinessLogicException("No open business date found for property.");
        }

        $authId = auth()->id();
        if (!$authId) {
            throw new BusinessLogicException("Authenticated posting operator is required.");
        }

        if ($userId !== null && $userId !== $authId) {
            throw new BusinessLogicException("The supplied user ID does not match the authenticated posting operator.");
        }

        $actorId = $authId;

        // Deterministic multi-line order: item_id ASC -> location_id ASC -> id ASC
        $sortedLines = $issue->lines->map(function ($line) {
            if (!$line->item_id || !$line->location_id) {
                throw new BusinessLogicException("Issue line is missing item or location.");
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
            if ($this->enrollmentRepository->hasEnrolledGroupForPropertyItem($propertyId, (string) $line->item_id)) {
                $enrolledCount++;
            }
        }

        if ($enrolledCount > 0 && $enrolledCount < $totalLines) {
            throw new RuntimeException("Mixed enrollment status detected across issue lines. Fail closed.");
        }

        $allEnrolled = ($enrolledCount === $totalLines);

        if (!$allEnrolled) {
            // Legacy path remains unchanged
            DB::transaction(function () use ($issue, $sortedLines, $businessDate, $actorId) {
                $occurredAt = \Illuminate\Support\Carbon::parse($issue->issued_at ?? $issue->created_at ?? now());

                // Acquire business date and financial period locks
                $this->coordinator->lockContext($issue->property_id, $businessDate->business_date, $occurredAt);

                foreach ($sortedLines as $line) {
                    $item = $this->itemRepository->find($line->item_id);
                    if (!$item) {
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
                    'status'    => IssueStatusEnum::Posted->value,
                    'posted_at' => now(),
                    'posted_by' => $actorId,
                ]);
            });
        } else {
            // All ENROLLED loop
            DB::transaction(function () use ($issue, $sortedLines, $businessDate, $actorId, $propertyId) {
                $occurredAt = \Illuminate\Support\Carbon::parse($issue->issued_at ?? $issue->created_at ?? now());

                // Acquire business date and financial period locks
                $this->coordinator->lockContext($issue->property_id, $businessDate->business_date, $occurredAt);

                $invocationService = app(\Modules\Finance\CostControl\Services\ControlledIssueValuationInvocationService::class);

                foreach ($sortedLines as $line) {
                    $scope = "property:{$propertyId}:location:{$line->location_id}:item:{$line->item_id}";
                    $avcoState = DB::table('cost_avco_states')
                        ->where('valuation_scope', $scope)
                        ->first();

                    if (!$avcoState) {
                        throw new RuntimeException("CostAvcoState not found for scope {$scope}");
                    }

                    $wac = $avcoState->weighted_average_unit_cost;
                    if ($wac === null) {
                        throw new RuntimeException("No valid prevailing carrying cost found for enrolled item: {$line->item_id}");
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

                    $invocationService->invokeIssue($propertyId, $line->location_id, $line->item_id, $intent, $actorId);
                }

                $this->issueRepository->update($issue->id, [
                    'status'    => IssueStatusEnum::Posted->value,
                    'posted_at' => now(),
                    'posted_by' => $actorId,
                ]);
            });
        }

        return $this->issueRepository->find($id);
    }
}
