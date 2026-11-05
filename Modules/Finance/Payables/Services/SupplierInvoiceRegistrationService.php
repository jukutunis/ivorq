<?php

namespace Modules\Finance\Payables\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Contracts\ProcurementReceiptEvidenceProvider;
use Modules\Operations\Purchasing\Enums\ReceiptSourceType;

class SupplierInvoiceRegistrationService
{
    public const PERMISSION = 'finance.payables.supplier-invoice.register';

    public function __construct(
        private readonly ThreeWayMatchingEngine $matchingEngine,
        private readonly SupplierInvoiceIntakeSourceService $intakeSourceService,
        private readonly ProcurementReceiptEvidenceProvider $receiptEvidence,
    ) {}

    public function registerFromSource(array $input, User $actor): array
    {
        $propertyId = $this->requiredString(
            $input['property_id'] ?? null,
            'Supplier invoice property is required.'
        );

        $this->assertActorMayRegister($actor, $propertyId);

        return DB::transaction(function () use ($propertyId, $input, $actor): array {
            $idempotencyKey = $this->nullableString($input['registration_idempotency_key'] ?? null);
            $replay = $idempotencyKey === null
                ? null
                : SupplierInvoice::with('lines')
                    ->where('property_id', $propertyId)
                    ->where('registration_idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
            $resolved = $replay
                ? $this->replayPayload($replay, $input)
                : $this->intakeSourceService->resolve($propertyId, $input);

            return $this->registerAndMatch($resolved, $actor);
        });
    }

    public function registerAndMatch(array $data, User $actor): array
    {
        $invoice = $this->register($data, $actor);
        $match = $this->matchingEngine->performMatch($invoice->fresh(['lines']));

        return [
            'invoice' => $invoice->fresh(['lines']),
            'match' => $match->fresh(['lines']),
        ];
    }

    public function register(array $data, User $actor): SupplierInvoice
    {
        $normalized = $this->normalizePayload($data);

        $this->assertActorMayRegister($actor, $normalized['property_id']);

        return DB::transaction(function () use ($normalized, $actor): SupplierInvoice {
            $this->assertSourceScope($normalized);

            if ($normalized['registration_idempotency_key'] !== null) {
                $replay = SupplierInvoice::with('lines')
                    ->where('property_id', $normalized['property_id'])
                    ->where('registration_idempotency_key', $normalized['registration_idempotency_key'])
                    ->lockForUpdate()
                    ->first();

                if ($replay) {
                    if (! hash_equals((string) $replay->source_fingerprint, $normalized['source_fingerprint'])
                        && ! $this->matchesLegacyPreDiscriminatorFingerprint($replay, $normalized)) {
                        throw new DomainException('Supplier invoice registration key was already used for different source evidence.');
                    }

                    return $replay;
                }
            }

            $this->intakeSourceService->assertReceiptCapacity($normalized);

            $existing = SupplierInvoice::with('lines')
                ->where('property_id', $normalized['property_id'])
                ->where('vendor_id', $normalized['vendor_id'])
                ->where('invoice_number', $normalized['invoice_number'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new DomainException('Supplier invoice already exists for this property, vendor, and invoice number.');
            }

            $invoice = SupplierInvoice::create([
                'property_id' => $normalized['property_id'],
                'vendor_id' => $normalized['vendor_id'],
                'purchase_order_id' => $normalized['purchase_order_id'],
                'goods_receipt_id' => $normalized['goods_receipt_id'],
                'receipt_source_type' => $normalized['receipt_source_type'],
                'invoice_number' => $normalized['invoice_number'],
                'registration_idempotency_key' => $normalized['registration_idempotency_key'],
                'source_fingerprint' => $normalized['source_fingerprint'],
                'invoice_date' => $normalized['invoice_date'],
                'due_date' => $normalized['due_date'],
                'currency_code' => $normalized['currency_code'],
                'status' => SupplierInvoice::STATUS_REGISTERED,
                'subtotal' => $normalized['subtotal'],
                'tax_amount' => $normalized['tax_amount'],
                'discount_amount' => $normalized['discount_amount'],
                'grand_total' => $normalized['grand_total'],
                'remarks' => $normalized['remarks'],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($normalized['lines'] as $line) {
                $invoice->lines()->create([
                    'purchase_order_line_id' => $line['purchase_order_line_id'],
                    'goods_receipt_line_id' => $line['goods_receipt_line_id'],
                    'receipt_commercial_evidence_hash' => $line['receipt_commercial_evidence_hash'],
                    'inventory_item_id' => $line['inventory_item_id'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            return $invoice->fresh(['lines']);
        });
    }

    private function assertActorMayRegister(User $actor, string $propertyId): void
    {
        $freshActor = User::query()->find($actor->id);

        if (! $freshActor || ! $freshActor->is_active) {
            throw new AuthorizationException('Supplier invoice registration actor is inactive or unresolved.');
        }

        if (! $freshActor->can(self::PERMISSION)) {
            throw new AuthorizationException('Supplier invoice registration permission is required.');
        }

        $hasPropertyAccess = $freshActor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (! $hasPropertyAccess) {
            throw new AuthorizationException('Supplier invoice registration requires active property access.');
        }
    }

    private function replayPayload(SupplierInvoice $replay, array $input): array
    {
        return [
            'property_id' => $replay->property_id,
            'vendor_id' => $replay->vendor_id,
            'purchase_order_id' => $input['purchase_order_id'] ?? null,
            'goods_receipt_id' => $input['goods_receipt_id'] ?? null,
            'receipt_source_type' => $input['receipt_source_type'] ?? $replay->receipt_source_type,
            'invoice_number' => $input['invoice_number'] ?? null,
            'invoice_date' => $input['invoice_date'] ?? null,
            'due_date' => $input['due_date'] ?? null,
            'currency_code' => $replay->currency_code,
            'tax_amount' => $replay->tax_amount,
            'discount_amount' => $replay->discount_amount,
            'remarks' => $input['remarks'] ?? null,
            'registration_idempotency_key' => $input['registration_idempotency_key'] ?? null,
            'lines' => $replay->lines->map(fn ($line): array => [
                'purchase_order_line_id' => $line->purchase_order_line_id,
                'goods_receipt_line_id' => $line->goods_receipt_line_id,
                'receipt_commercial_evidence_hash' => $line->receipt_commercial_evidence_hash,
                'inventory_item_id' => $line->inventory_item_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'line_total' => $line->line_total,
            ])->all(),
        ];
    }

    private function assertSourceScope(array $data): void
    {
        $property = DB::table('properties')
            ->where('id', $data['property_id'])
            ->where('is_active', true)
            ->first(['company_id']);

        if (! $property) {
            throw new DomainException('Supplier invoice property is not active or does not exist.');
        }

        $vendor = DB::table('vendors')
            ->where('id', $data['vendor_id'])
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('is_approved', true)
            ->first();

        if (! $vendor || $vendor->company_id !== $property->company_id) {
            throw new DomainException('Supplier invoice vendor is outside the active property scope.');
        }

        $purchaseOrder = DB::table('purchase_orders')
            ->where('id', $data['purchase_order_id'])
            ->whereNull('deleted_at')
            ->first();

        if (! $purchaseOrder || $purchaseOrder->property_id !== $data['property_id']) {
            throw new DomainException('Supplier invoice purchase order is outside the active property scope.');
        }

        $receiptLines = [];
        if ($data['goods_receipt_id'] !== null) {
            $authority = $this->receiptEvidence->sourceType(
                $data['property_id'],
                $data['purchase_order_id']
            );

            if ($data['receipt_source_type'] !== $authority) {
                throw new DomainException('Supplier invoice receipt source type conflicts with Purchase Order authority.');
            }

            $receipt = $this->receiptEvidence->receipt(
                $data['property_id'],
                $data['purchase_order_id'],
                $data['goods_receipt_id']
            );

            if (! $receipt) {
                throw new DomainException('Supplier invoice receiving evidence is outside the active property scope.');
            }

            $lineIds = array_values(array_filter(array_map(
                fn (array $line): ?string => $line['goods_receipt_line_id'],
                $data['lines']
            )));
            $receiptLines = collect($this->receiptEvidence->lines(
                $data['property_id'],
                $data['purchase_order_id'],
                $data['goods_receipt_id'],
                false,
                $lineIds
            ))->keyBy('id');
        } elseif ($data['receipt_source_type'] !== null) {
            throw new DomainException('Supplier invoice receipt source type requires receipt evidence.');
        }

        foreach ($data['lines'] as $line) {
            $purchaseOrderLine = DB::table('purchase_order_lines')
                ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
                ->where('purchase_order_lines.id', $line['purchase_order_line_id'])
                ->whereNull('purchase_order_lines.deleted_at')
                ->whereNull('purchase_orders.deleted_at')
                ->select('purchase_orders.property_id')
                ->first();

            if (! $purchaseOrderLine || $purchaseOrderLine->property_id !== $data['property_id']) {
                throw new DomainException('Supplier invoice purchase order line is outside the active property scope.');
            }

            if ($line['goods_receipt_line_id'] === null) {
                continue;
            }

            $goodsReceiptLine = $receiptLines->get($line['goods_receipt_line_id']);

            if (! $goodsReceiptLine
                || $goodsReceiptLine['purchase_order_line_id'] !== $line['purchase_order_line_id']
                || $goodsReceiptLine['inventory_item_id'] !== $line['inventory_item_id']
                || $goodsReceiptLine['commercial_evidence_hash'] !== $line['receipt_commercial_evidence_hash']) {
                throw new DomainException('Supplier invoice receiving line is outside the active property scope.');
            }
        }
    }

    private function normalizePayload(array $data): array
    {
        $invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));

        if ($invoiceNumber === '') {
            throw new DomainException('Supplier invoice number is required.');
        }

        if (strlen($invoiceNumber) > 255) {
            throw new DomainException('Supplier invoice number exceeds the supported length.');
        }

        $lines = $data['lines'] ?? [];

        if (! is_array($lines) || count($lines) === 0) {
            throw new DomainException('Supplier invoice requires at least one line.');
        }

        $normalizedLines = [];
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $quantity = $this->decimal($line['quantity'] ?? null, 3);
            $unitPrice = $this->decimal($line['unit_price'] ?? null, 2);

            if ($quantity <= 0) {
                throw new DomainException('Supplier invoice line quantity must be greater than zero.');
            }

            if ($unitPrice < 0) {
                throw new DomainException('Supplier invoice line unit price cannot be negative.');
            }

            $expectedLineTotal = round($quantity * $unitPrice, 2);
            $lineTotal = array_key_exists('line_total', $line)
                ? $this->decimal($line['line_total'], 2)
                : $expectedLineTotal;

            if (abs($lineTotal - $expectedLineTotal) > 0.01) {
                throw new DomainException('Supplier invoice line total must equal quantity multiplied by unit price.');
            }

            $subtotal += $lineTotal;

            $normalizedLines[] = [
                'purchase_order_line_id' => $this->nullableString($line['purchase_order_line_id'] ?? null),
                'goods_receipt_line_id' => $this->nullableString($line['goods_receipt_line_id'] ?? null),
                'receipt_commercial_evidence_hash' => $this->nullableString(
                    $line['receipt_commercial_evidence_hash'] ?? null
                ),
                'inventory_item_id' => $this->nullableString($line['inventory_item_id'] ?? null),
                'description' => trim((string) ($line['description'] ?? '')),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];

            if ($normalizedLines[array_key_last($normalizedLines)]['purchase_order_line_id'] === null) {
                throw new DomainException('Supplier invoice line requires purchase order line provenance.');
            }

            if ($normalizedLines[array_key_last($normalizedLines)]['description'] === '') {
                throw new DomainException('Supplier invoice line description is required.');
            }
        }

        $taxAmount = $this->decimal($data['tax_amount'] ?? 0, 2);
        $discountAmount = $this->decimal($data['discount_amount'] ?? 0, 2);

        if ($taxAmount < 0 || $discountAmount < 0) {
            throw new DomainException('Supplier invoice tax and discount amounts cannot be negative.');
        }

        $normalized = [
            'property_id' => $this->requiredString($data['property_id'] ?? null, 'Supplier invoice property is required.'),
            'vendor_id' => $this->requiredString($data['vendor_id'] ?? null, 'Supplier invoice vendor is required.'),
            'purchase_order_id' => $this->requiredString($data['purchase_order_id'] ?? null, 'Supplier invoice purchase order is required.'),
            'goods_receipt_id' => $this->nullableString($data['goods_receipt_id'] ?? null),
            'receipt_source_type' => $this->nullableString($data['receipt_source_type'] ?? null),
            'invoice_number' => $invoiceNumber,
            'invoice_date' => CarbonImmutable::parse($data['invoice_date'] ?? null)->toDateString(),
            'due_date' => isset($data['due_date']) ? CarbonImmutable::parse($data['due_date'])->toDateString() : null,
            'currency_code' => strtoupper($this->requiredString($data['currency_code'] ?? null, 'Supplier invoice currency is required.')),
            'subtotal' => round($subtotal, 2),
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'grand_total' => round($subtotal + $taxAmount - $discountAmount, 2),
            'remarks' => $data['remarks'] ?? null,
            'registration_idempotency_key' => $this->nullableString($data['registration_idempotency_key'] ?? null),
            'lines' => $normalizedLines,
        ];

        if ($normalized['registration_idempotency_key'] !== null
            && strlen($normalized['registration_idempotency_key']) > 96) {
            throw new DomainException('Supplier invoice registration key exceeds the supported length.');
        }

        if ($normalized['goods_receipt_id'] === null) {
            if ($normalized['receipt_source_type'] !== null
                || collect($normalized['lines'])->contains(
                    fn (array $line): bool => $line['goods_receipt_line_id'] !== null
                        || $line['receipt_commercial_evidence_hash'] !== null
                )) {
                throw new DomainException('Receipt provenance cannot be supplied without receipt evidence.');
            }
        } else {
            if (ReceiptSourceType::tryFrom((string) $normalized['receipt_source_type']) === null) {
                throw new DomainException('Supplier invoice receipt source type is invalid.');
            }

            foreach ($normalized['lines'] as $line) {
                if ($line['goods_receipt_line_id'] === null) {
                    throw new DomainException('Receipt-backed supplier invoice line requires receipt-line provenance.');
                }
            }
        }

        $fingerprintEvidence = $normalized;
        unset($fingerprintEvidence['registration_idempotency_key']);
        $normalized['source_fingerprint'] = hash(
            'sha256',
            json_encode($fingerprintEvidence, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
        );

        return $normalized;
    }

    private function requiredString(mixed $value, string $message): string
    {
        $normalized = $this->nullableString($value);

        if ($normalized === null) {
            throw new DomainException($message);
        }

        return $normalized;
    }

    private function matchesLegacyPreDiscriminatorFingerprint(
        SupplierInvoice $replay,
        array $normalized,
    ): bool {
        if ($replay->receipt_source_type !== ReceiptSourceType::LegacyReceivingDocument->value
            || $replay->lines->contains(
                fn ($line): bool => $line->receipt_commercial_evidence_hash !== null
            )) {
            return false;
        }

        $legacyEvidence = $normalized;
        unset(
            $legacyEvidence['registration_idempotency_key'],
            $legacyEvidence['source_fingerprint'],
            $legacyEvidence['receipt_source_type']
        );

        foreach ($legacyEvidence['lines'] as &$line) {
            unset($line['receipt_commercial_evidence_hash']);
        }
        unset($line);

        $legacyFingerprint = hash(
            'sha256',
            json_encode($legacyEvidence, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
        );

        return hash_equals((string) $replay->source_fingerprint, $legacyFingerprint);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function decimal(mixed $value, int $precision): float
    {
        if (! is_numeric($value)) {
            throw new DomainException('Supplier invoice numeric evidence is invalid.');
        }

        return round((float) $value, $precision);
    }
}
