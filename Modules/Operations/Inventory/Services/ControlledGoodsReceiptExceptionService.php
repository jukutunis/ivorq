<?php

namespace Modules\Operations\Inventory\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\GoodsReceiptExceptionResolutionTypeEnum;
use Modules\Operations\Inventory\Enums\GoodsReceiptExceptionStatusEnum;
use Modules\Operations\Inventory\Enums\GoodsReceiptLinePostingDispositionEnum;
use Modules\Operations\Inventory\Enums\GoodsReceiptStatusEnum;
use Modules\Operations\Inventory\Models\GoodsReceipt;
use Modules\Operations\Inventory\Models\GoodsReceiptLine;
use Modules\Operations\Inventory\Models\GoodsReceiptLineCommercialEvidence;
use Modules\Operations\Inventory\Models\GoodsReceiptLineException;
use Modules\Operations\Purchasing\Contracts\ProcurementReceiptEvidenceProvider;
use Modules\Operations\Purchasing\Enums\ReceiptSourceType;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;
use Modules\Operations\Receiving\Enums\DiscrepancyTypeEnum;
use Modules\Operations\Receiving\Enums\InspectionResultEnum;
use Shared\Services\CurrentPropertyService;

class ControlledGoodsReceiptExceptionService
{
    public const REPORT_PERMISSION = 'inventory.purchasing.goods-receipt.receive';

    public const RESOLVE_PERMISSION = 'inventory.purchasing.goods-receipt.exception.resolve';

    public function __construct(
        private readonly ProcurementReceiptEvidenceProvider $receiptEvidence,
        private readonly CurrentPropertyService $currentProperty,
    ) {}

    public function report(
        GoodsReceipt $receipt,
        string $lineId,
        array $evidence,
        User $actor,
    ): GoodsReceiptLineException {
        return DB::transaction(function () use ($receipt, $lineId, $evidence, $actor) {
            $lockedReceipt = GoodsReceipt::withoutGlobalScope('property')->lockForUpdate()->findOrFail($receipt->id);
            $this->assertActor($actor, self::REPORT_PERMISSION, $lockedReceipt->property_id);
            $this->assertControlledAuthority($lockedReceipt);

            if ($lockedReceipt->status !== GoodsReceiptStatusEnum::Draft) {
                throw new DomainException('Goods Receipt exceptions may only be reported while the receipt is DRAFT.');
            }

            $line = GoodsReceiptLine::withoutGlobalScope('property')->lockForUpdate()->findOrFail($lineId);
            $poLine = PurchaseOrderLine::query()->findOrFail($line->purchase_order_line_id);

            if ($line->goods_receipt_id !== $lockedReceipt->id
                || $line->property_id !== $lockedReceipt->property_id
                || $poLine->purchase_order_id !== $lockedReceipt->purchase_order_id) {
                throw new DomainException('Goods Receipt exception provenance is inconsistent.');
            }

            $discrepancy = isset($evidence['discrepancy_type']) && $evidence['discrepancy_type'] !== ''
                ? DiscrepancyTypeEnum::tryFrom((string) $evidence['discrepancy_type'])
                : null;
            $inspection = isset($evidence['inspection_result']) && $evidence['inspection_result'] !== ''
                ? InspectionResultEnum::tryFrom((string) $evidence['inspection_result'])
                : null;

            if (! $discrepancy && ! $inspection) {
                throw new DomainException('A controlled discrepancy type or inspection result is required.');
            }

            if (($inspection === InspectionResultEnum::Fail || $inspection === InspectionResultEnum::Conditional)
                && trim((string) ($evidence['reason'] ?? '')) === '') {
                throw new DomainException('Failed or conditional inspection requires source-backed reason evidence.');
            }

            $observedQuantity = $this->decimal((string) ($evidence['observed_quantity'] ?? '0'));
            if (bccomp($observedQuantity, '0.000', 3) <= 0) {
                throw new DomainException('Observed quantity must be positive.');
            }

            $idempotencyKey = trim((string) ($evidence['idempotency_key'] ?? ''));
            if ($idempotencyKey === '') {
                throw new DomainException('Exception idempotency key is required.');
            }

            $reason = trim((string) ($evidence['reason'] ?? ''));
            if ($reason === '') {
                throw new DomainException('Exception reason is required.');
            }

            $fingerprint = $this->fingerprint([
                'goods_receipt_id' => $lockedReceipt->id,
                'goods_receipt_line_id' => $line->id,
                'purchase_order_id' => $lockedReceipt->purchase_order_id,
                'purchase_order_line_id' => $line->purchase_order_line_id,
                'discrepancy_type' => $discrepancy?->value,
                'inspection_result' => $inspection?->value,
                'observed_quantity' => $observedQuantity,
                'reason' => $reason,
                'notes' => trim((string) ($evidence['notes'] ?? '')),
            ]);

            $existing = GoodsReceiptLineException::withoutGlobalScope('property')
                ->where('property_id', $lockedReceipt->property_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if (! hash_equals($existing->source_fingerprint, $fingerprint)) {
                    throw new DomainException('GOODS_RECEIPT_EXCEPTION_IDEMPOTENCY_CONFLICT');
                }

                return $existing;
            }

            return GoodsReceiptLineException::withoutGlobalScope('property')->create([
                'id' => (string) Str::ulid(),
                'property_id' => $lockedReceipt->property_id,
                'goods_receipt_id' => $lockedReceipt->id,
                'goods_receipt_line_id' => $line->id,
                'purchase_order_id' => $lockedReceipt->purchase_order_id,
                'purchase_order_line_id' => $line->purchase_order_line_id,
                'discrepancy_type' => $discrepancy?->value,
                'inspection_result' => $inspection?->value,
                'observed_quantity' => $observedQuantity,
                'accepted_quantity' => $line->received_quantity,
                'reason' => $reason,
                'notes' => trim((string) ($evidence['notes'] ?? '')) ?: null,
                'status' => GoodsReceiptExceptionStatusEnum::Open->value,
                'reported_by' => $actor->id,
                'reported_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'source_fingerprint' => $fingerprint,
            ]);
        });
    }

    public function resolve(
        GoodsReceiptLineException $exception,
        GoodsReceiptExceptionResolutionTypeEnum $resolution,
        ?string $acceptedQuantity,
        string $reason,
        User $actor,
    ): GoodsReceiptLineException {
        return DB::transaction(function () use ($exception, $resolution, $acceptedQuantity, $reason, $actor) {
            $source = GoodsReceiptLineException::withoutGlobalScope('property')->findOrFail($exception->id);
            $receipt = GoodsReceipt::withoutGlobalScope('property')->lockForUpdate()->findOrFail($source->goods_receipt_id);
            $locked = GoodsReceiptLineException::withoutGlobalScope('property')->lockForUpdate()->findOrFail($exception->id);
            $line = GoodsReceiptLine::withoutGlobalScope('property')->lockForUpdate()->findOrFail($locked->goods_receipt_line_id);

            $this->assertActor($actor, self::RESOLVE_PERMISSION, $receipt->property_id);
            $this->assertControlledAuthority($receipt);

            if ($locked->status !== GoodsReceiptExceptionStatusEnum::Open) {
                throw new DomainException('Goods Receipt exception is already resolved.');
            }
            if ($locked->reported_by === $actor->id) {
                throw new AuthorizationException('Goods Receipt exception reporter cannot resolve the same evidence.');
            }
            if ($receipt->status !== GoodsReceiptStatusEnum::Draft) {
                throw new DomainException('Goods Receipt exception resolution requires a DRAFT receipt.');
            }
            if (trim($reason) === '') {
                throw new DomainException('Exception resolution reason is required.');
            }

            $resolvedAcceptedQuantity = (string) $line->received_quantity;

            if ($resolution === GoodsReceiptExceptionResolutionTypeEnum::AdjustAcceptedQuantity) {
                $resolvedAcceptedQuantity = $this->decimal((string) $acceptedQuantity);
                $poLine = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($line->purchase_order_line_id);

                if (bccomp($resolvedAcceptedQuantity, '0.000', 3) <= 0
                    || bccomp($resolvedAcceptedQuantity, (string) $line->received_quantity, 3) >= 0
                    || bccomp($resolvedAcceptedQuantity, (string) $locked->observed_quantity, 3) > 0
                    || bccomp($resolvedAcceptedQuantity, (string) $poLine->remaining_quantity, 3) > 0) {
                    throw new DomainException('Accepted quantity must reduce the Draft quantity within observed and Purchase Order capacity.');
                }

                $line->received_quantity = $resolvedAcceptedQuantity;
                $line->save();
            } elseif ($resolution === GoodsReceiptExceptionResolutionTypeEnum::RejectLine) {
                $resolvedAcceptedQuantity = '0.000';
                $line->posting_disposition = GoodsReceiptLinePostingDispositionEnum::Rejected;
                $line->save();
            } elseif ($resolution === GoodsReceiptExceptionResolutionTypeEnum::VoidReceipt) {
                $resolvedAcceptedQuantity = '0.000';
                $this->applyVoid($receipt, $reason, $actor);
            }

            $locked->status = GoodsReceiptExceptionStatusEnum::Resolved;
            $locked->resolution_type = $resolution;
            $locked->accepted_quantity = $resolvedAcceptedQuantity;
            $locked->resolution_reason = trim($reason);
            $locked->resolved_by = $actor->id;
            $locked->resolved_at = now();
            $locked->save();

            return $locked->fresh(['goodsReceipt', 'goodsReceiptLine', 'reporter', 'resolver']);
        });
    }

    public function voidReceipt(GoodsReceipt $receipt, string $reason, User $actor): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $reason, $actor) {
            $locked = GoodsReceipt::withoutGlobalScope('property')->lockForUpdate()->findOrFail($receipt->id);
            $this->assertActor($actor, self::RESOLVE_PERMISSION, $locked->property_id);
            $this->assertControlledAuthority($locked);

            if ($locked->created_by === $actor->id) {
                throw new AuthorizationException('Goods Receipt creator cannot approve its void.');
            }
            if (trim($reason) === '') {
                throw new DomainException('Goods Receipt void reason is required.');
            }
            if ($locked->exceptions()->where('status', GoodsReceiptExceptionStatusEnum::Open->value)->exists()) {
                throw new DomainException('Open exception must be resolved explicitly before receipt void.');
            }

            $this->applyVoid($locked, $reason, $actor);

            return $locked->fresh(['lines', 'exceptions']);
        });
    }

    public function submitForConfirmation(GoodsReceipt $receipt, User $actor): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $actor) {
            $locked = GoodsReceipt::withoutGlobalScope('property')->lockForUpdate()->findOrFail($receipt->id);
            $this->assertActor($actor, self::REPORT_PERMISSION, $locked->property_id);
            $this->assertControlledAuthority($locked);

            if ($locked->status !== GoodsReceiptStatusEnum::Draft) {
                throw new DomainException('Only a DRAFT Goods Receipt may be submitted for confirmation.');
            }
            if ($locked->exceptions()->where('status', GoodsReceiptExceptionStatusEnum::Open->value)->exists()) {
                throw new DomainException('Open Goods Receipt exceptions must be resolved before confirmation.');
            }

            $accepted = $locked->lines()
                ->where('posting_disposition', GoodsReceiptLinePostingDispositionEnum::Accepted->value)
                ->lockForUpdate()
                ->get();

            if ($accepted->isEmpty()) {
                throw new DomainException('Goods Receipt requires at least one accepted line for confirmation.');
            }

            foreach ($accepted as $line) {
                $poLine = PurchaseOrderLine::query()->findOrFail($line->purchase_order_line_id);
                if ($line->property_id !== $locked->property_id
                    || $poLine->purchase_order_id !== $locked->purchase_order_id) {
                    throw new DomainException('Goods Receipt confirmation provenance is inconsistent.');
                }
            }

            $locked->status = GoodsReceiptStatusEnum::ConfirmationPending;
            $locked->save();

            return $locked->fresh(['lines', 'exceptions']);
        });
    }

    private function applyVoid(GoodsReceipt $receipt, string $reason, User $actor): void
    {
        if (! in_array($receipt->status, [GoodsReceiptStatusEnum::Draft, GoodsReceiptStatusEnum::ConfirmationPending], true)) {
            throw new DomainException('Only a non-posted Goods Receipt may be voided.');
        }
        if ($receipt->lines()->whereNotNull('stock_movement_id')->exists()
            || GoodsReceiptLineCommercialEvidence::withoutGlobalScope('property')
                ->where('goods_receipt_id', $receipt->id)->exists()) {
            throw new DomainException('Goods Receipt with posting evidence cannot be voided.');
        }

        $receipt->status = GoodsReceiptStatusEnum::Voided;
        $receipt->void_reason = trim($reason);
        $receipt->voided_by = $actor->id;
        $receipt->voided_at = now();
        $receipt->save();
    }

    private function assertActor(User $actor, string $permission, string $propertyId): void
    {
        if (auth()->id() !== $actor->id || ! $actor->is_active
            || $this->currentProperty->resolveOrFail() !== $propertyId
            || ! $actor->properties()->where('properties.id', $propertyId)->wherePivot('status', 'active')->exists()
            || ! $actor->hasPermissionTo($permission)) {
            throw new AuthorizationException('Goods Receipt exception action is not authorized for the active Property.');
        }
    }

    private function assertControlledAuthority(GoodsReceipt $receipt): void
    {
        $source = $this->receiptEvidence->sourceType($receipt->property_id, $receipt->purchase_order_id, true);
        if ($source !== ReceiptSourceType::InventoryGoodsReceipt->value) {
            throw new DomainException('CONTROLLED_GOODS_RECEIPT_NOT_AUTHORIZED_FOR_PURCHASE_ORDER_SOURCE');
        }
    }

    private function decimal(string $quantity): string
    {
        return number_format((float) $quantity, 3, '.', '');
    }

    private function fingerprint(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
