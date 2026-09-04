<?php

namespace Modules\Operations\Receiving\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Receiving\Repositories\ReceivingLineRepository;
use Modules\Operations\Receiving\Repositories\ReceivingRepository;
use Shared\Exceptions\BusinessLogicException;

class ReceivingService
{
    public function __construct(
        protected ReceivingRepository $receivingRepository,
        protected ReceivingLineRepository $receivingLineRepository,
        protected ReceivingValidationService $validationService,
        protected ReceivingApprovalIntegrationService $approvalIntegrationService,
        protected CostDeliveryModePort $costDeliveryMode,
    ) {}

    public function createDraft(array $data): ReceivingDocument
    {
        return DB::transaction(function () use ($data) {
            $this->validationService->validateCreation($data);
            $this->lockMutationItems((string) $data['property_id'], collect($data['lines'] ?? [])->pluck('inventory_item_id')->all());

            // In real app, generate GRN Number based on sequence.
            $data['grn_number'] = 'GRN-'.date('Y').'-'.str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            $data['status'] = ReceivingDocumentStatusEnum::Draft->value;

            $document = $this->receivingRepository->create($data);

            if (isset($data['lines']) && is_array($data['lines'])) {
                foreach ($data['lines'] as $lineData) {
                    $lineData['receiving_document_id'] = $document->id;
                    $this->receivingLineRepository->create($lineData);
                }
            }

            return $document->load('lines');
        });
    }

    public function submit(string $documentId): void
    {
        DB::transaction(function () use ($documentId) {
            $document = $this->receivingRepository->findById($documentId);
            if (! $document) {
                throw new BusinessLogicException('Document not found');
            }
            $this->lockMutationItems((string) $document->property_id, $document->lines()->pluck('inventory_item_id')->all());

            $document->update(['status' => ReceivingDocumentStatusEnum::Submitted->value]);

            $this->approvalIntegrationService->submitForApproval($document);
        });
    }

    public function cancel(string $documentId): void
    {
        DB::transaction(function () use ($documentId): void {
            $document = $this->receivingRepository->findById($documentId);
            if (! $document) {
                throw new BusinessLogicException('Document not found');
            }
            $this->lockMutationItems((string) $document->property_id, $document->lines()->pluck('inventory_item_id')->all());
            $document->update(['status' => ReceivingDocumentStatusEnum::Cancelled->value]);
        });
    }

    private function lockMutationItems(string $propertyId, array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
        sort($itemIds, SORT_STRING);
        foreach ($itemIds as $itemId) {
            $this->costDeliveryMode->lockForDocumentMutation($propertyId, $itemId);
        }
    }
}
