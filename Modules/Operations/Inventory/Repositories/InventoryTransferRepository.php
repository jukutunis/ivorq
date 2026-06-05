<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Shared\Exceptions\NotFoundException;

class InventoryTransferRepository
{
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
        return InventoryTransfer::create($data)->fresh();
    }

    public function update(string $id, array $data): InventoryTransfer
    {
        $transfer = $this->find($id);
        $transfer->update($data);

        return $transfer->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
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
