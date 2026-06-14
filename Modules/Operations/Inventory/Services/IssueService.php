<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Repositories\InventoryIssueRepository;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;

class IssueService
{
    public function __construct(
        private InventoryIssueRepository $issueRepository,
        private StockMovementService $stockMovementService,
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

        DB::transaction(function () use ($issue, $userId) {
            foreach ($issue->lines as $line) {
                $item = $this->itemRepository->find($line->item_id);

                // BR-018: stamp current WAC as unit_cost on the stock card
                $this->stockMovementService->issue(
                    $issue->property_id,
                    $line->item_id,
                    $line->location_id,
                    (string) $line->quantity,
                    $issue->id,
                    $issue->issue_number,
                    $userId
                );
            }

            $this->issueRepository->update($issue->id, [
                'status'    => IssueStatusEnum::Posted->value,
                'posted_at' => now(),
                'posted_by' => $userId ?? auth()->id(),
            ]);
        });

        return $this->issueRepository->find($id);
    }
}
