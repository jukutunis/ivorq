<?php

namespace Modules\Operations\Engineering\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Engineering\Enums\AssetRequestStatusEnum;
use Modules\Operations\Engineering\Events\AssetRequestApproved;
use Modules\Operations\Engineering\Events\AssetRequestFulfilled;
use Modules\Operations\Engineering\Events\AssetRequestRejected;
use Modules\Operations\Engineering\Models\AssetRequest;
use Modules\Operations\Engineering\Repositories\AssetRequestRepository;

class AssetRequestService
{
    public function __construct(
        private AssetRequestRepository $repository,
    ) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, $perPage);
    }

    public function find(string $id): AssetRequest
    {
        return $this->repository->find($id);
    }

    public function create(array $data): AssetRequest
    {
        return $this->repository->create($data);
    }

    /**
     * Update asset request fields. Status is not allowed here —
     * use approve() / reject() / fulfill() / cancel() instead.
     */
    public function update(string $id, array $data): AssetRequest
    {
        unset($data['status']);

        return $this->repository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->repository->delete($id);
    }

    /**
     * Approve a pending asset request.
     *
     * Validates pending → approved transition.
     * Sets approved_by and approved_at. Fires AssetRequestApproved.
     */
    public function approve(string $id, ?string $approverId = null): AssetRequest
    {
        $request = $this->repository->find($id);
        $this->guardTransition($request, AssetRequestStatusEnum::Approved);

        $updated = $this->repository->update($id, [
            'status'      => AssetRequestStatusEnum::Approved->value,
            'approved_by' => $approverId ?? auth()->id(),
            'approved_at' => now(),
        ]);

        event(new AssetRequestApproved($updated));

        return $updated;
    }

    /**
     * Reject a pending asset request.
     *
     * Validates pending → rejected transition.
     * Sets rejected_by, rejected_at, and rejection_reason. Fires AssetRequestRejected.
     */
    public function reject(string $id, string $reason, ?string $rejectorId = null): AssetRequest
    {
        $request = $this->repository->find($id);
        $this->guardTransition($request, AssetRequestStatusEnum::Rejected);

        $updated = $this->repository->update($id, [
            'status'           => AssetRequestStatusEnum::Rejected->value,
            'rejected_by'      => $rejectorId ?? auth()->id(),
            'rejected_at'      => now(),
            'rejection_reason' => $reason,
        ]);

        event(new AssetRequestRejected($updated, $reason));

        return $updated;
    }

    /**
     * Fulfill an approved asset request.
     *
     * Validates approved → fulfilled transition.
     * Sets fulfilled_by and fulfilled_at. Fires AssetRequestFulfilled.
     */
    public function fulfill(string $id, ?string $fulfillerId = null): AssetRequest
    {
        $request = $this->repository->find($id);
        $this->guardTransition($request, AssetRequestStatusEnum::Fulfilled);

        $updated = $this->repository->update($id, [
            'status'       => AssetRequestStatusEnum::Fulfilled->value,
            'fulfilled_by' => $fulfillerId ?? auth()->id(),
            'fulfilled_at' => now(),
        ]);

        event(new AssetRequestFulfilled($updated));

        return $updated;
    }

    /**
     * Cancel a pending or approved asset request.
     *
     * Validates the transition via the enum guard before persisting.
     */
    public function cancel(string $id): AssetRequest
    {
        $request = $this->repository->find($id);
        $this->guardTransition($request, AssetRequestStatusEnum::Cancelled);

        return $this->repository->update($id, [
            'status' => AssetRequestStatusEnum::Cancelled->value,
        ]);
    }

    private function guardTransition(AssetRequest $request, AssetRequestStatusEnum $target): void
    {
        if (! $request->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot transition asset request from {$request->status->label()} to {$target->label()}.",
                ],
            ]);
        }
    }
}
