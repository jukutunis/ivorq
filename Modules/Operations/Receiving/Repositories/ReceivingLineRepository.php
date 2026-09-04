<?php

namespace Modules\Operations\Receiving\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Receiving\Models\ReceivingLine;

class ReceivingLineRepository
{
    public function __construct(private readonly CostDeliveryModePort $costDeliveryMode) {}

    public function create(array $data): ReceivingLine
    {
        return DB::transaction(function () use ($data): ReceivingLine {
            $document = ReceivingDocument::findOrFail($data['receiving_document_id']);
            $this->costDeliveryMode->lockForDocumentMutation(
                (string) $document->property_id,
                (string) $data['inventory_item_id'],
            );

            return ReceivingLine::create($data);
        });
    }

    public function findById(string $id): ?ReceivingLine
    {
        return ReceivingLine::find($id);
    }

    public function update(string $id, array $data): ReceivingLine
    {
        return DB::transaction(function () use ($id, $data): ReceivingLine {
            $line = ReceivingLine::findOrFail($id);
            $document = ReceivingDocument::findOrFail($line->receiving_document_id);
            $itemIds = array_unique([(string) $line->inventory_item_id, (string) ($data['inventory_item_id'] ?? $line->inventory_item_id)]);
            sort($itemIds, SORT_STRING);
            foreach ($itemIds as $itemId) {
                $this->costDeliveryMode->lockForDocumentMutation((string) $document->property_id, $itemId);
            }
            $line = ReceivingLine::whereKey($id)->lockForUpdate()->firstOrFail();
            $line->update($data);

            return $line->fresh();
        });
    }

    public function delete(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $line = ReceivingLine::findOrFail($id);
            $document = ReceivingDocument::findOrFail($line->receiving_document_id);
            $this->costDeliveryMode->lockForDocumentMutation((string) $document->property_id, (string) $line->inventory_item_id);
            $line = ReceivingLine::whereKey($id)->lockForUpdate()->firstOrFail();

            return (bool) $line->delete();
        });
    }
}
