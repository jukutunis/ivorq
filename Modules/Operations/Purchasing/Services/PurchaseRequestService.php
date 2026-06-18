<?php

namespace Modules\Operations\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Repositories\PurchaseRequestLineRepository;
use Modules\Operations\Purchasing\Repositories\PurchaseRequestRepository;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Foundation\User\Models\User;
use Modules\Finance\Budgeting\Services\BudgetVarianceService;
use Shared\Exceptions\BusinessLogicException;

class PurchaseRequestService
{
    public function __construct(
        protected PurchaseRequestRepository $repository,
        protected PurchaseRequestLineRepository $lineRepository,
        protected ApprovalEngineService $approvalEngine,
        protected BudgetVarianceService $budgetVarianceService
    ) {}

    public function createWithLines(array $data, array $lines): PurchaseRequest
    {
        return DB::transaction(function () use ($data, $lines) {
            $data['estimated_total'] = 0; // will calculate below
            
            $pr = $this->repository->create($data);
            
            $total = 0;
            foreach ($lines as $lineData) {
                $lineData['purchase_request_id'] = $pr->id;
                
                // Calculate line total
                $qty = $lineData['quantity'] ?? 0;
                $cost = $lineData['estimated_unit_cost'] ?? 0;
                $lineData['estimated_total_cost'] = $qty * $cost;
                
                $this->lineRepository->create($lineData);
                $total += $lineData['estimated_total_cost'];
            }
            
            $this->repository->update($pr->id, ['estimated_total' => $total]);
            
            return $pr->fresh(['department', 'requester', 'lines.inventoryItem', 'lines.unit']);
        });
    }

    public function updateWithLines(string $id, array $data, ?array $lines): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $data, $lines) {
            $pr = $this->repository->findOrFail($id);
            
            if ($pr->status->value !== PurchaseRequestStatusEnum::Draft->value) {
                throw new BusinessLogicException('Cannot update a Purchase Request that is not in Draft status.');
            }

            if ($lines !== null) {
                $this->lineRepository->deleteByPurchaseRequestId($id);
                
                $total = 0;
                foreach ($lines as $lineData) {
                    $lineData['purchase_request_id'] = $pr->id;
                    $qty = $lineData['quantity'] ?? 0;
                    $cost = $lineData['estimated_unit_cost'] ?? 0;
                    $lineData['estimated_total_cost'] = $qty * $cost;
                    
                    $this->lineRepository->create($lineData);
                    $total += $lineData['estimated_total_cost'];
                }
                
                $data['estimated_total'] = $total;
            }

            return $this->repository->update($id, $data);
        });
    }

    public function cancel(string $id): PurchaseRequest
    {
        $pr = $this->repository->findOrFail($id);
        
        if (in_array($pr->status->value, [PurchaseRequestStatusEnum::Cancelled->value, PurchaseRequestStatusEnum::ConvertedToPO->value])) {
            throw new BusinessLogicException('Purchase Request cannot be cancelled from its current status.');
        }

        return $this->repository->update($id, [
            'status' => PurchaseRequestStatusEnum::Cancelled->value
        ]);
    }

    public function submit(string $id): PurchaseRequest
    {
        return DB::transaction(function () use ($id) {
            $pr = $this->repository->findOrFail($id);
            
            if ($pr->status->value !== PurchaseRequestStatusEnum::Draft->value) {
                throw new BusinessLogicException('Only Draft Purchase Requests can be submitted.');
            }

            // Enforce Budget Control
            if ($pr->department_id && $pr->estimated_total > 0) {
                $this->budgetVarianceService->validateDepartmentBudget(
                    $pr->property_id,
                    $pr->department_id,
                    $pr->required_date->year,
                    $pr->required_date->month,
                    $pr->estimated_total
                );
            }

            // Evaluate workflow
            $step = $this->approvalEngine->submitForApproval($pr, $pr->requester_id);

            $pr = $this->repository->update($id, [
                'status' => PurchaseRequestStatusEnum::PendingReview->value
            ]);

            return $pr;
        });
    }

    public function approve(string $id, User $user, ?string $remarks = null): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $user, $remarks) {
            $pr = $this->repository->findOrFail($id);
            
            if ($pr->status->value !== PurchaseRequestStatusEnum::PendingReview->value) {
                throw new BusinessLogicException('Only Submitted Purchase Requests can be approved.');
            }

            $nextStep = $this->approvalEngine->approve(
                $pr, 
                'purchasing', 
                $pr->estimated_total, 
                $user->id, 
                $user->name, 
                null, // roleName can be fetched if needed
                $remarks
            );

            if (!$nextStep) {
                // Fully approved
                $pr = $this->repository->update($id, [
                    'status' => PurchaseRequestStatusEnum::Approved->value
                ]);
            }

            return $pr;
        });
    }

    public function reject(string $id, User $user, ?string $remarks = null): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $user, $remarks) {
            $pr = $this->repository->findOrFail($id);
            
            if ($pr->status->value !== PurchaseRequestStatusEnum::PendingReview->value) {
                throw new BusinessLogicException('Only Submitted Purchase Requests can be rejected.');
            }

            $this->approvalEngine->reject(
                $pr, 
                'purchasing', 
                $user->id, 
                $user->name, 
                null, 
                $remarks
            );

            return $this->repository->update($id, [
                'status' => PurchaseRequestStatusEnum::Rejected->value
            ]);
        });
    }
}
