<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Services\InventoryDocumentMutationGate;
use Shared\Exceptions\NotFoundException;

class InventoryTransferRepository
{
    public function __construct(private readonly InventoryDocumentMutationGate $mutationGate) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryTransfer::with(['fromLocation', 'toLocation'])->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from_location_id'])) {
            $query->where('from_location_id', $filters['from_location_id']);
        }

        if (! empty($filters['to_location_id'])) {
            $query->where('to_location_id', $filters['to_location_id']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): InventoryTransfer
    {
        $transfer = InventoryTransfer::with([
            'fromLocation',
            'toLocation',
            'lines.item.unit',
            'requestedBy',
            'approvedBy',
            'completedBy',
            'cancelledBy',
        ])->find($id);

        throw_if(! $transfer, new NotFoundException('InventoryTransfer'));

        return $transfer;
    }

    public function findOrFail(string $id): InventoryTransfer
    {
        return InventoryTransfer::findOrFail($id);
    }

    public function create(array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($data): InventoryTransfer {
            $this->mutationGate->lock(
                (string) $data['property_id'],
                collect($data['lines'] ?? [])->pluck('item_id')->all(),
            );

            return InventoryTransfer::create($data)->fresh();
        });
    }

    public function update(string $id, array $data, bool $ownershipAlreadyLocked = false): InventoryTransfer
    {
        return DB::transaction(function () use ($id, $data, $ownershipAlreadyLocked): InventoryTransfer {
            $candidate = $this->find($id);
            $itemIds = $candidate->lines->pluck('item_id')
                ->merge(collect($data['lines'] ?? [])->pluck('item_id'))->all();
            if (! $ownershipAlreadyLocked) {
                $this->mutationGate->lock((string) $candidate->property_id, $itemIds);
            }
            $transfer = InventoryTransfer::whereKey($id)->lockForUpdate()->firstOrFail();
            $transfer->update($data);

            return $transfer->fresh();
        });
    }

    public function delete(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $candidate = $this->find($id);
            $this->mutationGate->lock((string) $candidate->property_id, $candidate->lines->pluck('item_id')->all());

            return (bool) InventoryTransfer::whereKey($id)->lockForUpdate()->firstOrFail()->delete();
        });
    }

    public function pending(): Collection
    {
        return InventoryTransfer::where('status', TransferStatusEnum::Submitted)
            ->with(['fromLocation', 'toLocation', 'requestedBy'])
            ->latest()
            ->get();
    }

    public function byStatus(TransferStatusEnum $status): Collection
    {
        return InventoryTransfer::where('status', $status)
            ->with(['fromLocation', 'toLocation'])
            ->latest()
            ->get();
    }
}
