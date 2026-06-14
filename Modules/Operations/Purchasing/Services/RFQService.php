<?php

namespace Modules\Operations\Purchasing\Services;

use Modules\Operations\Purchasing\Repositories\RFQRepository;
use Modules\Operations\Purchasing\Models\RFQ;
use Modules\Foundation\Notification\Models\AppNotification;
use Modules\Foundation\Task\Services\TaskService;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Exception;

class RFQService
{
    public function __construct(
        protected RFQRepository $repository,
        protected TaskService $taskService
    ) {
    }

    public function createFromPurchaseRequest(PurchaseRequest $pr, array $vendorIds, string $deadlineAt): RFQ
    {
        if ($pr->status !== 'Approved') {
            throw new Exception("Only approved Purchase Requests can be converted to RFQ.");
        }

        $rfq = $this->repository->create([
            'property_id' => $pr->property_id,
            'purchase_request_id' => $pr->id,
            'rfq_number' => 'RFQ-' . date('Ymd-His'),
            'title' => 'RFQ for PR ' . $pr->pr_number,
            'deadline_at' => $deadlineAt,
            'status' => 'Open',
            'created_by' => auth()->id(),
        ]);

        foreach ($vendorIds as $vendorId) {
            $rfq->vendors()->attach($vendorId, [
                'id' => (string) str(\Illuminate\Support\Str::ulid()),
                'status' => 'Invited'
            ]);
        }

        // Notify purchasing agent
        $this->taskService->create([
            'property_id' => $rfq->property_id,
            'task_type' => 'Review',
            'source_module' => 'purchasing',
            'taskable_type' => get_class($rfq),
            'taskable_id' => $rfq->id,
            'title' => 'Review Quotations for RFQ ' . $rfq->rfq_number,
            'description' => 'Review quotations by deadline: ' . $deadlineAt,
            'priority' => \Shared\Enums\PriorityEnum::High->value,
            'status' => \Modules\Foundation\Task\Enums\TaskStatusEnum::Open->value,
            'due_date' => $deadlineAt,
        ]);

        AppNotification::create([
            'property_id' => $rfq->property_id,
            'user_id' => auth()->id(),
            'type' => 'purchasing.rfq_deadline',
            'priority' => 'normal',
            'title' => "RFQ Deadline approaching",
            'body' => "RFQ {$rfq->rfq_number} deadline is {$deadlineAt}",
        ]);

        return $rfq;
    }
}
