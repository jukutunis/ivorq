<?php

namespace Modules\Operations\Purchasing\Contracts;

interface ProcurementReceiptEvidenceProvider
{
    public function sourceType(string $propertyId, string $purchaseOrderId, bool $lockForUpdate = false): string;

    public function receipts(string $propertyId, string $purchaseOrderId): array;

    public function receipt(
        string $propertyId,
        string $purchaseOrderId,
        string $receiptId,
        bool $lockForUpdate = false,
    ): ?array;

    public function lines(
        string $propertyId,
        string $purchaseOrderId,
        string $receiptId,
        bool $lockForUpdate = false,
        array $lineIds = [],
    ): array;
}
