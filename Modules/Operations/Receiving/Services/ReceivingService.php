<?php

namespace Modules\Operations\Receiving\Services;

use Modules\Operations\Receiving\Repositories\ReceivingRepository;
use Modules\Operations\Receiving\Repositories\ReceivingLineRepository;
use Illuminate\Support\Facades\DB;
use Shared\Exceptions\BusinessLogicException;

class ReceivingService
{
    public function __construct(
        protected ReceivingRepository $receivingRepository,
        protected ReceivingLineRepository $receivingLineRepository,
        protected ReceivingValidationService $validationService,
        protected ReceivingApprovalIntegrationService $approvalIntegrationService
    ) {}

    public function createDraft(array $data): \Modules\Operations\Receiving\Models\ReceivingDocument
    {
        return DB::transaction(function () use ($data) {
            $this->validationService->validateCreation($data);

            // In real app, generate GRN Number based on sequence.
            $data['grn_number'] = 'GRN-' . date('Y') . '-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            $data['status'] = \Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum::Draft->value;

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
            if (!$document) {
                throw new BusinessLogicException("Document not found");
            }
            
            $document->update(['status' => \Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum::Submitted->value]);
            
            $this->approvalIntegrationService->submitForApproval($document);
        });
    }

    public function cancel(string $documentId): void
    {
        $document = $this->receivingRepository->findById($documentId);
        if (!$document) {
            throw new BusinessLogicException("Document not found");
        }
        
        $document->update(['status' => \Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum::Cancelled->value]);
    }
}
