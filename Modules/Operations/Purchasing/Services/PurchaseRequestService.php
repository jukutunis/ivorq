<?php

namespace Modules\Operations\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Repositories\PurchaseRequestLineRepository;
use Modules\Operations\Purchasing\Repositories\PurchaseRequestRepository;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Foundation\User\Models\User;
use Shared\Exceptions\BusinessLogicException;

class PurchaseRequestService
{
    public function __construct(
        protected PurchaseRequestRepository $repository,
        protected PurchaseRequestLineRepository $lineRepository,
        protected ApprovalEngineService $approvalEngine
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

            // TODO: Budget integration will be implemented later in Budgeting/Finance sprint.
            // Skip budget reservation for Sprint 09B.3.

            // Evaluate workflow
            $step = $this->approvalEngine->submitDocument($pr, 'purchasing');

            $pr = $this->repository->update($id, [
                'status' => PurchaseRequestStatusEnum::Submitted->value
            ]);

            return $pr;
        });
    }

    public function approve(string $id, User $user, ?string $remarks = null): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $user, $remarks) {
            $pr = $this->repository->findOrFail($id);
            
            if ($pr->status->value !== PurchaseRequestStatusEnum::Submitted->value) {
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
            
            if ($pr->status->value !== PurchaseRequestStatusEnum::Submitted->value) {
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
