<?php

namespace Modules\Operations\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Services\InventoryDocumentMutationGate;
use Shared\Exceptions\NotFoundException;

class InventoryReceiptRepository
{
    public function __construct(private readonly InventoryDocumentMutationGate $mutationGate) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryReceipt::latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['supplier_name'])) {
            $query->where('supplier_name', 'like', '%'.$filters['supplier_name'].'%');
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): InventoryReceipt
    {
        $receipt = InventoryReceipt::with([
            'lines.item.unit',
            'lines.location',
            'postedBy',
            'cancelledBy',
        ])->find($id);

        throw_if(! $receipt, new NotFoundException('InventoryReceipt'));

        return $receipt;
    }

    public function findOrFail(string $id): InventoryReceipt
    {
        return InventoryReceipt::findOrFail($id);
    }

    public function create(array $data): InventoryReceipt
    {
        return DB::transaction(function () use ($data): InventoryReceipt {
            $this->mutationGate->lock(
                (string) $data['property_id'],
                collect($data['lines'] ?? [])->pluck('item_id')->all(),
            );

            return InventoryReceipt::create($data)->fresh();
        });
    }

    public function update(string $id, array $data, bool $ownershipAlreadyLocked = false): InventoryReceipt
    {
        return DB::transaction(function () use ($id, $data, $ownershipAlreadyLocked): InventoryReceipt {
            $candidate = $this->find($id);
            $itemIds = $candidate->lines->pluck('item_id')
                ->merge(collect($data['lines'] ?? [])->pluck('item_id'))->all();
            if (! $ownershipAlreadyLocked) {
                $this->mutationGate->lock((string) $candidate->property_id, $itemIds);
            }
            $receipt = InventoryReceipt::whereKey($id)->lockForUpdate()->firstOrFail();
            $receipt->update($data);

            return $receipt->fresh();
        });
    }

    public function delete(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $candidate = $this->find($id);
            $this->mutationGate->lock((string) $candidate->property_id, $candidate->lines->pluck('item_id')->all());

            return (bool) InventoryReceipt::whereKey($id)->lockForUpdate()->firstOrFail()->delete();
        });
    }

    public function byStatus(ReceiptStatusEnum $status): Collection
    {
        return InventoryReceipt::where('status', $status)
            ->latest()
            ->get();
    }
}
