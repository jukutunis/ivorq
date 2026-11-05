<?php

namespace Modules\Operations\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Budgeting\Services\BudgetVarianceService;
use Modules\Foundation\Approval\Models\ApprovalAction;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\PurchaseRequestRevisionSnapshot;
use Modules\Operations\Purchasing\Repositories\PurchaseRequestLineRepository;
use Shared\Exceptions\BusinessLogicException;
use Shared\Services\CurrentPropertyService;

class PurchaseRequestService
{
    public function __construct(
        private PurchaseRequestLineRepository $lineRepository,
        private ApprovalEngineService $approvalEngine,
        private BudgetVarianceService $budgetVarianceService,
        private PurchasingDocumentNumberAllocator $numberAllocator,
        private CurrentPropertyService $currentProperty,
    ) {}

    public function createWithLines(array $data, array $lines, User $actor): PurchaseRequest
    {
        return DB::transaction(function () use ($data, $lines, $actor): PurchaseRequest {
            $property = $this->activeProperty();
            $canonicalLines = $this->canonicalLines($lines, $property->id);
            $this->assertDepartment($data['department_id'], $property->id);

            $idempotencyKey = $this->nullableString($data['idempotency_key'] ?? null);
            $fingerprint = $this->fingerprint([
                'property_id' => $property->id,
                'requester_id' => $actor->id,
                'department_id' => $data['department_id'],
                'required_date' => $data['required_date'],
                'remarks' => $data['remarks'] ?? null,
                'currency_code' => $property->currency,
                'lines' => $canonicalLines,
            ]);

            if ($idempotencyKey !== null) {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                    "purchasing:purchase-request:{$property->id}:{$idempotencyKey}",
                ]);

                $replay = PurchaseRequest::query()
                    ->where('property_id', $property->id)
                    ->where('creation_idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($replay) {
                    if (! hash_equals((string) $replay->source_fingerprint, $fingerprint)) {
                        throw new BusinessLogicException('Purchase Request idempotency key was already used for different content.');
                    }

                    return $replay->load(['department', 'requester', 'lines.inventoryItem', 'lines.unit']);
                }
            }

            $request = PurchaseRequest::create([
                'property_id' => $property->id,
                'request_no' => $this->numberAllocator->allocate($property->id, 'PR', now()->year),
                'creation_idempotency_key' => $idempotencyKey,
                'source_fingerprint' => $fingerprint,
                'revision_number' => 1,
                'current_revision_created_by' => $actor->id,
                'department_id' => $data['department_id'],
                'requester_id' => $actor->id,
                'required_date' => $data['required_date'],
                'currency_code' => strtoupper((string) $property->currency),
                'exchange_rate' => 1,
                'estimated_total' => 0,
                'status' => PurchaseRequestStatusEnum::Draft,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $total = $this->persistLines($request, $canonicalLines, $actor);
            $request->update(['estimated_total' => $total]);

            return $request->fresh(['department', 'requester', 'lines.inventoryItem', 'lines.unit']);
        });
    }

    public function updateWithLines(string $id, array $data, ?array $lines, User $actor): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $data, $lines, $actor): PurchaseRequest {
            $request = PurchaseRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($request->status !== PurchaseRequestStatusEnum::Draft) {
                throw new BusinessLogicException('Only a Draft Purchase Request may be edited.');
            }

            if (isset($data['department_id'])) {
                $this->assertDepartment($data['department_id'], $request->property_id);
            }

            if ($lines !== null) {
                $canonicalLines = $this->canonicalLines($lines, $request->property_id);
                $this->lineRepository->deleteByPurchaseRequestId($request->id);
                $data['estimated_total'] = $this->persistLines($request, $canonicalLines, $actor);
            }

            $data['current_revision_created_by'] = $actor->id;
            $data['updated_by'] = $actor->id;
            $request->update($data);

            return $request->fresh(['department', 'requester', 'lines.inventoryItem', 'lines.unit']);
        });
    }

    public function submit(string $id, User $actor): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $actor): PurchaseRequest {
            $request = PurchaseRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($request->status !== PurchaseRequestStatusEnum::Draft) {
                throw new BusinessLogicException('Only a Draft Purchase Request may be submitted.');
            }

            if ($request->lines()->count() === 0) {
                throw new BusinessLogicException('A Purchase Request must contain at least one line.');
            }

            if ($request->department_id && (float) $request->estimated_total > 0) {
                $this->budgetVarianceService->validateDepartmentBudget(
                    $request->property_id,
                    $request->department_id,
                    $request->required_date->year,
                    $request->required_date->month,
                    $request->estimated_total
                );
            }

            $revisionMakerId = $this->revisionMakerId($request);
            $this->approvalEngine->submitForApproval($request, $revisionMakerId);
            $request->update([
                'status' => PurchaseRequestStatusEnum::PendingReview,
                'updated_by' => $actor->id,
            ]);

            return $request->fresh(['department', 'requester', 'lines.inventoryItem', 'lines.unit']);
        });
    }

    public function approve(string $id, User $actor, ?string $remarks = null): PurchaseRequest
    {
        return $this->decide($id, $actor, 'approve', $remarks);
    }

    public function reject(string $id, User $actor, ?string $remarks = null): PurchaseRequest
    {
        if ($this->nullableString($remarks) === null) {
            throw new BusinessLogicException('A rejection reason is required.');
        }

        return $this->decide($id, $actor, 'reject', $remarks);
    }

    public function cancel(string $id, User $actor, string $reason): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $actor, $reason): PurchaseRequest {
            $request = PurchaseRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if (! in_array($request->status, [
                PurchaseRequestStatusEnum::Draft,
                PurchaseRequestStatusEnum::PendingReview,
            ], true)) {
                throw new BusinessLogicException('Purchase Request cannot be cancelled from its current status.');
            }

            $reason = trim($reason);
            if ($reason === '') {
                throw new BusinessLogicException('A cancellation reason is required.');
            }

            if ($request->status === PurchaseRequestStatusEnum::PendingReview) {
                $this->approvalEngine->cancel($this->activeApproval($request), $actor->id, $reason);
            }

            $request->update([
                'status' => PurchaseRequestStatusEnum::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'updated_by' => $actor->id,
            ]);

            return $request->fresh(['department', 'requester', 'lines.inventoryItem', 'lines.unit']);
        });
    }

    public function revise(string $id, User $actor): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $actor): PurchaseRequest {
            $request = PurchaseRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $property = $this->assertRevisionAuthority($actor, $request->property_id);

            if ($request->status !== PurchaseRequestStatusEnum::Rejected) {
                throw new BusinessLogicException('Only a Rejected Purchase Request may be revised.');
            }

            $lines = $request->lines()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $approval = $this->rejectedApproval($request);
            $revisionMakerId = $this->revisionMakerId($request);

            if (! $request->rejected_by || ! $request->rejected_at || trim((string) $request->rejection_reason) === '') {
                throw new BusinessLogicException('Purchase Request rejection evidence is incomplete.');
            }

            $header = [
                'purchase_request_id' => $request->id,
                'request_no' => $request->request_no,
                'department_id' => $request->department_id,
                'requester_id' => $request->requester_id,
                'required_date' => $request->required_date?->toDateString(),
                'currency_code' => $request->currency_code,
                'exchange_rate' => (string) $request->exchange_rate,
                'estimated_total' => (string) $request->estimated_total,
                'remarks' => $request->remarks,
                'rejected_by' => $request->rejected_by,
                'rejected_at' => $request->rejected_at->toISOString(),
                'rejection_reason' => $request->rejection_reason,
                'approval_request_id' => $approval->id,
            ];
            $commercialLines = $lines->map(fn ($line): array => $line->only([
                'id',
                'purchase_request_id',
                'inventory_item_id',
                'description',
                'quantity',
                'unit_id',
                'estimated_unit_cost',
                'estimated_total_cost',
                'remarks',
            ]))->all();
            $fingerprint = $this->fingerprint([
                'revision_number' => (int) $request->revision_number,
                'header' => $header,
                'lines' => $commercialLines,
            ]);

            PurchaseRequestRevisionSnapshot::create([
                'purchase_request_id' => $request->id,
                'revision_number' => $request->revision_number,
                'property_id' => $request->property_id,
                'company_id' => $property->company_id,
                'document_number' => $request->request_no,
                'decision_status' => PurchaseRequestStatusEnum::Rejected->value,
                'revision_maker_id' => $revisionMakerId,
                'rejected_by' => $request->rejected_by,
                'approval_request_id' => $approval->id,
                'rejection_reason' => $request->rejection_reason,
                'rejected_at' => $request->rejected_at,
                'commercial_header' => $header,
                'commercial_lines' => $commercialLines,
                'source_fingerprint' => $fingerprint,
            ]);

            $request->update([
                'revision_number' => ((int) $request->revision_number) + 1,
                'current_revision_created_by' => $actor->id,
                'status' => PurchaseRequestStatusEnum::Draft,
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'updated_by' => $actor->id,
            ]);

            return $request->fresh([
                'department',
                'requester',
                'lines.inventoryItem',
                'lines.unit',
                'revisionSnapshots',
            ]);
        });
    }

    private function decide(string $id, User $actor, string $decision, ?string $remarks): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $actor, $decision, $remarks): PurchaseRequest {
            $request = PurchaseRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($request->status !== PurchaseRequestStatusEnum::PendingReview) {
                throw new BusinessLogicException('Only a Pending Review Purchase Request may be decided.');
            }

            if ($this->revisionMakerId($request) === $actor->id) {
                throw new BusinessLogicException('The Purchase Request requester cannot approve or reject their own request; this applies to the current revision maker.');
            }

            if (! $actor->can('purchasing.purchase-request.approve')) {
                throw new BusinessLogicException('Purchase Request approval permission is required.');
            }

            if (! $actor->properties()
                ->where('properties.id', $request->property_id)
                ->wherePivot('status', 'active')
                ->exists()) {
                throw new BusinessLogicException('Purchase Request decision requires active Property membership.');
            }

            $approval = $this->activeApproval($request);

            if (ApprovalAction::query()
                ->where('approval_request_id', $approval->id)
                ->where('approval_step_id', $approval->current_step_id)
                ->where('user_id', $actor->id)
                ->exists()) {
                throw new BusinessLogicException('The checker has already recorded a decision for this approval step.');
            }

            if ($decision === 'approve') {
                $this->approvalEngine->approve($approval, $actor->id, $remarks);
            } else {
                $this->approvalEngine->reject($approval, $actor->id, $remarks);
            }

            return $request->fresh(['department', 'requester', 'lines.inventoryItem', 'lines.unit']);
        });
    }

    private function activeApproval(PurchaseRequest $request): ApprovalRequest
    {
        $approval = ApprovalRequest::query()
            ->where('approvable_type', PurchaseRequest::class)
            ->where('approvable_id', $request->id)
            ->whereIn('status', ['Pending', 'In Progress'])
            ->latest('requested_at')
            ->first();

        if (! $approval) {
            throw new BusinessLogicException('Active Purchase Request approval evidence was not found.');
        }

        return $approval;
    }

    private function rejectedApproval(PurchaseRequest $request): ApprovalRequest
    {
        $approval = ApprovalRequest::query()
            ->where('approvable_type', PurchaseRequest::class)
            ->where('approvable_id', $request->id)
            ->where('status', 'Rejected')
            ->latest('completed_at')
            ->first();

        if (! $approval) {
            throw new BusinessLogicException('Rejected Purchase Request approval evidence was not found.');
        }

        return $approval;
    }

    private function revisionMakerId(PurchaseRequest $request): string
    {
        $makerId = $request->currentRevisionMakerId();

        if (! $makerId) {
            throw new BusinessLogicException('Purchase Request revision maker evidence is unavailable.');
        }

        return $makerId;
    }

    private function assertRevisionAuthority(User $actor, string $propertyId): Property
    {
        $property = $this->activeProperty();

        if ($property->id !== $propertyId
            || ! $actor->can('purchasing.purchase-request.edit')
            || ! $actor->properties()
                ->where('properties.id', $propertyId)
                ->wherePivot('status', 'active')
                ->exists()) {
            throw new BusinessLogicException('Purchase Request revision requires edit authority and active Property membership.');
        }

        return $property;
    }

    private function activeProperty(): Property
    {
        return Property::query()
            ->whereKey($this->currentProperty->resolveOrFail())
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function assertDepartment(string $departmentId, string $propertyId): void
    {
        if (! Department::query()->withoutGlobalScope('property')
            ->whereKey($departmentId)
            ->where('property_id', $propertyId)
            ->where('is_active', true)
            ->exists()) {
            throw new BusinessLogicException('Purchase Request department is outside the active Property.');
        }
    }

    private function canonicalLines(array $lines, string $propertyId): array
    {
        return collect($lines)->map(function (array $line) use ($propertyId): array {
            if (! is_numeric($line['quantity'] ?? null)
                || (float) $line['quantity'] <= 0
                || ! is_numeric($line['estimated_unit_cost'] ?? null)
                || (float) $line['estimated_unit_cost'] < 0) {
                throw new BusinessLogicException('Purchase Request quantity and estimated unit cost are invalid.');
            }

            $item = InventoryItem::query()->withoutGlobalScope('property')
                ->whereKey($line['inventory_item_id'])
                ->where('property_id', $propertyId)
                ->where('is_active', true)
                ->first();
            $unit = InventoryUnit::query()->withoutGlobalScope('property')
                ->whereKey($line['unit_id'])
                ->where('property_id', $propertyId)
                ->first();

            if (! $item || ! $unit) {
                throw new BusinessLogicException('Purchase Request item or UOM is outside the active Property.');
            }

            return [
                'inventory_item_id' => $item->id,
                'description' => $item->name,
                'quantity' => (string) $line['quantity'],
                'unit_id' => $unit->id,
                'estimated_unit_cost' => (string) $line['estimated_unit_cost'],
                'remarks' => $line['remarks'] ?? null,
            ];
        })->values()->all();
    }

    private function persistLines(PurchaseRequest $request, array $lines, User $actor): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            $line['purchase_request_id'] = $request->id;
            $line['estimated_total_cost'] = (float) $line['quantity'] * (float) $line['estimated_unit_cost'];
            $line['created_by'] = $actor->id;
            $line['updated_by'] = $actor->id;
            $this->lineRepository->create($line);
            $total += $line['estimated_total_cost'];
        }

        return $total;
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
